<?php
date_default_timezone_set('Europe/Bucharest');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

echo "Starting...\n";

//sleep(rand(30, 100));

$headers = array(
  'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
  'Accept-Encoding' => 'gzip, deflate',
  'Accept-Language' => 'en-US,en;q=0.8,ro;q=0.6,pl;q=0.4,de;q=0.2,ru;q=0.2,fr;q=0.2',
  'Cache-Control' => 'max-age=0',
  'Connection' => 'keep-alive',
  'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/47.0.2526.80 Safari/537.36'
);

foreach ($config['accounts'] as $account) {
    $client = new Client([
        'base_uri' => 'http://' . $account['server'],
        'cookies' => true
    ]);

	// Login
	doLogin($client, $headers, $account['username'], $account['password']);
    sleep(rand(2,4));
	$farmsList = getFarmsList($client, $headers);

    sleep(rand(2,5));
    $attackFarmsIds = array();
    $lastTime = time() - 30 * 60;
    foreach ($farmsList['farms'] as $farm) {
      if (!$farm['isAttacked'] && $farm['prevAttack'] < 3 && $farm['prevAttackTime'] < $lastTime) {
        $attackFarmsIds[] = $farm['id'];
      }
    }
    doAttackFarms($client, $headers, $farmsList['lid'], $farmsList['a'], $attackFarmsIds);
}

echo "Finished.\n";

// Requests

function doLogin($client, $headers, $username, $password) {
  $response = $client->request('GET', '/?lang=ro', array('headers' => $headers));
  $crawler = new Crawler($response->getBody()->getContents());
  $timestamp = $crawler->filter('input[name=login]')->first()->attr('value');

  sleep(rand(2, 4));

  $data = array(
    'name' => $username,
    'password' => $password,
    's1' => 'Login',
    'w' => '1280:800',
    'login' => $timestamp,
    'lowRes' => 0
  );
  $lHeaders = array_merge($headers, array('Referrer' => $url));
  $response = $client->post('/dorf1.php', array(
      'headers' => $lHeaders,
      'form_params' => $data,
      'debug' => true
  ));

  $crawler = new Crawler($response->getBody()->getContents());
  $isLogoutLink = $crawler->filter('ul#outOfGame li.logout')->count();

  if (!$isLogoutLink) {
    die('Login failed.');
  }
}

function getFarmsList($client, $headers) {
  $farmList = array();

  $response = $client->get('/build.php?tt=99&id=39', [
    'headers' => $headers
  ]);
  $crawler = new Crawler($response->getBody()->getContents());
  $form = $crawler->filter('#raidList form')->first();
  $farmList['lid'] = $form->filter('input[name=lid]')->attr('value');
  $farmList['a'] = $form->filter('input[name=a]')->attr('value');

  $form->filter('tbody tr.slotRow')->each(function($node, $i) use (&$farmList) {
    $time = $node->filter('td.lastRaid a')->text();
    $actualTime = time();
    if (stripos($time, 'astăzi') !== false) {
      $actualTime = strtotime(date('d-m-Y') . str_replace('astăzi ', '', $time));
    } else if (stripos($time, 'ieri') !== false) {
      $actualTime = strtotime(date('d-m-Y') . str_replace('ieri ', '', $time) . '-1 day');
    }

    $farmList['farms'][] = array(
      'name' => $node->filter('td.village a')->text(),
      'id' => preg_replace('/slot\[(.*?)\]/', '$1', $node->filter('td.checkbox input')->attr('name')),
      'isAttacked' => (bool) $node->filter('td.village img')->count(),
      'prevAttack' => preg_replace('/.*?iReport(\d)/', '$1', $node->filter('td.lastRaid img')->first()->attr('class')),
      'prevAttackTime' => $actualTime
    );
  });

  return $farmList;
}

function doAttackFarms($client, $headers, $farmListId, $farmListA, $farmIds) {
  $data = array(
      'action' => 'startRaid',
      'a' => $farmListA,
      'sort' => 'distance',
      'direction' => 'asc',
      'lid' => $farmListId
  );
  foreach ($farmIds as $farmId) {
    $data['slot[' . $farmId . ']'] = 'on';
  }

  $response = $client->post('/build.php?gid=16&tt=99', [
      'headers' => $headers,
      'form_params' => $data,
      'debug' => true
  ]);

  return $response;
}
