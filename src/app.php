<?php declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/config.php';


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
	$locations = $step3Content->query('//div[@class="center-textDiv"]/text()');

	/** @var DOMElement $location */
	foreach($locations as $location)
	{
		$locationName = trim($location->textContent);
		// echo $locationName . PHP_EOL;

		if(in_array(strtolower($locationName), WATCH_LOCATIONS))
		{
			$message = 'Found new appointment at ' . ucwords($locationName);
			echo $message . PHP_EOL;
			notify($message, 'New Appointment', PAGE_URL);
			exit(0);
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