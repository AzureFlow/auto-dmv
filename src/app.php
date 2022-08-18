<?php declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/config.php';


$cooldown = [];

$client = new Client([
	'base_uri' => PAGE_URL,
	RequestOptions::HEADERS => [
		'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
		'accept-language' => 'en-US,en;q=0.9',
		'referer' => PAGE_URL,
		'user-agent' => USER_AGENT,
	],
	RequestOptions::COOKIES => true,
]);

echo 'Watching the following locations: ' . implode(array_map('ucwords', WATCH_LOCATIONS)) . PHP_EOL . PHP_EOL;
while(true)
{
	$step1Resp = $client->get('');
	$step1Content = xpathFromContent($step1Resp->getBody()->getContents());
	$step1Form = getForm($step1Content);
	$step1Form['StepControls[1].Model.Value'] = '1';
	$step2Resp = $client->post('', [
		RequestOptions::FORM_PARAMS => $step1Form,
	]);

	$step2Content = xpathFromContent($step2Resp->getBody()->getContents());
	$step2Form = getForm($step2Content);
	$step2Form['StepControls[0].Model.Value'] = '5'; // 5 (Advance In-Car Appointments), 1 (Today In-Car Appointments)
	$step3Resp = $client->post('', [
		RequestOptions::FORM_PARAMS => $step2Form,
	]);

	$step3Content = xpathFromContent($step3Resp->getBody()->getContents());
	$step3Form = getForm($step3Content);
	$locations = $step3Content->query('//div[@class="center-textDiv"]');

	/** @var DOMElement $location */
	foreach($locations as $location)
	{
		$locationName = trim($step3Content->query($location->getNodePath() . '/text()')->item(0)->textContent);
		$id = $location->parentNode->getAttribute('data-id');
		// echo $locationName . PHP_EOL;

		if(in_array(strtolower($locationName), WATCH_LOCATIONS))
		{
			if(!isset($cooldown[$locationName]) || $cooldown[$locationName] + ALERT_COOLDOWN_SECONDS <= time())
			{
				$step3Form['StepControls[1].Model.Value'] = $id;
				$step4Resp = $client->post('', [
					RequestOptions::FORM_PARAMS => $step3Form,
				]);
				$step4Content = xpathFromContent($step4Resp->getBody()->getContents());
				$step4Form = getForm($step4Content);

				$dateScript = $step4Content->query('//script[contains(text(), "minDate")]/text()')->item(0)->textContent;

				$minDateRaw = extractText($dateScript, 'minDate');
				$minDate = $date = DateTime::createFromFormat('Y-m-d', $minDateRaw, new DateTimeZone('America/Chicago'));
				$minDate->add(new DateInterval('P1D'));

				$maxDateRaw = extractText($dateScript, 'maxDate');
				$maxDate = $date = DateTime::createFromFormat('Y-m-d', $maxDateRaw, new DateTimeZone('America/Chicago'));
				$maxDate2 = clone $maxDate;
				$maxDate->sub(new DateInterval('P1D'));

				// echo $minDate->format('Y-m-d') . ' - ' . $maxDate->format('Y-m-d') . PHP_EOL;

				$period = new DatePeriod($minDate, new DateInterval('P1D'), $maxDate2);

				$timeText = '';
				/** @var DateTime $value */
				foreach($period as $value)
				{
					echo 'Checking times for ' . $value->format('M j') . PHP_EOL;
					$step4Form['StepControls[2].Model.Value'] = $value->format('Y-m-d');

					$timesResp = $client->post(
						'https://ilsosappt.cxmflow.com/Appointment/AmendStep?stepControlTriggerId=23f8fdc6-da6d-415a-bd5d-b88918c79b83&targetStepControlId=e86a060e-e040-46f1-9afe-92ff9d6cbd17',
						[
							RequestOptions::FORM_PARAMS => $step4Form,
						]
					);
					$timesContent = xpathFromContent($timesResp->getBody()->getContents());
					$times = $timesContent->query('//select[@id="35d81233-3067-43e2-a1bf-632a0a239a5a"]/option[position()>1]/@data-datetime');
					$timesDt = [];

					/** @var DOMElement $time */
					foreach($times as $time)
					{
						$temp = DateTime::createFromFormat('n/j/Y g:i:s A', $time->textContent, new DateTimeZone('America/Chicago'));
						$timesDt[] = $temp;

						// echo $time->textContent . ' = ' . PHP_EOL;
						// var_dump($temp);
					}

					if(!empty($timesDt))
					{
						$timeDtText = array_map(static function(DateTime $value) {
							return "\t{$value->format('g:i A')}";
						}, $timesDt);
						$timeText .= "{$value->format('M j')}:\n" . implode("\n", $timeDtText) . "\n\n";
					}
				}

				$message = 'Found new appointment at ' . ucwords($locationName) . "!\n\nTimes:\n$timeText";
				echo $message . PHP_EOL;
				notify($message, 'New Appointment', PAGE_URL);
				$cooldown[$locationName] = time();
			}
		}
	}

	echo 'Waiting...' . PHP_EOL;
	sleep(WAIT_TIME);
}


/**
 * Converts html to an XPath.
 * @param string $content The HTML content.
 * @return DOMXPath Returns the XPath result.
 */
function xpathFromContent(string $content): DOMXPath
{
	$doc = new DOMDocument();
	@$doc->loadHTML($content);
	return new DOMXPath($doc);
}

/**
 * @param DOMXPath $content
 * @return array
 */
function getForm(DOMXPath $content): array
{
	$form = [];

	$formItems = $content->query('//form//input');
	// $formPost = $content->query('//form/@action')->item(0)->textContent;

	/** @var DOMElement $item */
	foreach($formItems as $item)
	{
		$itemName = $item->getAttribute('name');
		$itemValue = $item->getAttribute('value');

		$form[$itemName] = $itemValue;
	}

	return $form;
}

/**
 * Sends a Pushover notification.
 * @param string $message Message
 * @param string $title Title
 * @param string $url URL
 * @param int $priority HIGH = 1
 * NORMAL = 0
 * LOW = -1
 * SILENT = -2
 * @return bool Was the request successful?
 * @noinspection PhpUnhandledExceptionInspection
 * @noinspection PhpDocMissingThrowsInspection
 */
function notify(string $message, string $title = '', string $url = '', int $priority = 0): bool
{
	$client = new GuzzleHttp\Client([
		RequestOptions::HTTP_ERRORS => false,
	]);
	$resp = $client->post('https://api.pushover.net/1/messages.json', [
		'json' => [
			'token' => PUSHOVER_API_KEY,
			'user' => PUSHOVER_USER_KEY,
			'message' => $message,
			'title' => $title,
			'url' => $url,
			'sound' => 'pianobar',
			// 'sound' => 'metal_gear_alert',
			'priority' => $priority,
		],
	]);

	return $resp->getStatusCode() === 200;
}

/**
 * @param string $haystack
 * @param string $thing
 * @return string
 */
function extractText(string $haystack, string $thing): string
{
	$text = explode('\',', explode($thing, $haystack)[1])[0];
	return substr($text, strpos($text, '\'') + 1);
}