<?php
function drupal_paragraph_save ($drupal, $id, $content) {
  $current_node = null;

  $ch = curl_init();

  if ($id) {
    curl_setopt($ch, CURLOPT_URL, "{$drupal['url']}/entity/paragraph/{$id}?_format=json");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
  }
  else {
    curl_setopt($ch, CURLOPT_URL, "{$drupal['url']}/entity/paragraph?_format=json");
    curl_setopt($ch, CURLOPT_POST, true);
  }

  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-type: application/json',
  ]);
  curl_setopt($ch, CURLOPT_USERPWD, "{$drupal['user']}:{$drupal['pass']}");
  curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

  $result = curl_exec($ch);
  if ($result[0] !== '{') {
    print $result;
    exit(1);
  }

  $result = json_decode($result, true);

  if (!array_key_exists('id', $result)) {
    print_r($result);
    exit(1);
  }

  return $result;
}
