<?php
$drupalEntityConf = [
  'taxonomy' => [
    'entityHandle' => 'taxonomy/term/%',
    'createHandle' => 'taxonomy/term',
    'idField' => 'tid',
  ],
  'media' => [
    'entityHandle' => 'media/%/edit',
    'createHandle' => 'entity/media',
    'idField' => 'mid',
  ],
  'file' => [
    'entityHandle' => 'entity/file/%',
    'idField' => 'fid',
  ],
  'path_alias' => [
    'entityHandle' => 'entity/path_alias/%',
    'idField' => 'id',
  ],
];

class DrupalRestAPI {
  function __construct ($options = array()) {
    $this->options = $options;

    if (!array_key_exists('curl_options', $this->options)) {
      $this->options['curl_options'] = array();
    }
    if (array_key_exists('verbose', $this->options)) {
      $this->options['curl_options'][CURLOPT_VERBOSE] = $this->options['verbose'];
    }

    $this->ch = curl_init();
    $this->sessionHeaders = [];
    $this->setOptions();
    $this->auth($this->ch);
  }

  function setOptions () {
    foreach ($this->options['curl_options'] as $k => $v) {
      curl_setopt($this->ch, $k, $v);
    }
  }

  function auth () {
    if (array_key_exists('authMethod', $this->options) && $this->options['authMethod'] === 'cookie') {
      $this->authCookie();
    } else {
      $this->authBasicAuth();
    }
  }

  function authBasicAuth () {
    if (isset($this->options['user'])) {
      curl_setopt($this->ch, CURLOPT_USERPWD, "{$this->options['user']}:{$this->options['pass']}");
      curl_setopt($this->ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    }
  }

  function authCookie () {
    print "{$this->options['url']}/user/login\n";
    curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/user/login");
    curl_setopt($this->ch, CURLOPT_COOKIEFILE, ""); // use cookies, but don't save them
    curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($this->ch, CURLOPT_POST, 1);

    // This array will hold the field names and values.
    $postdata=array(
      "name"=>$this->options['user'],
      "pass"=>$this->options['pass'],
      "form_id"=>"user_login_form",
      "op"=>"Log in"
    );
    // Tell curl we're going to send $postdata as the POST data
    curl_setopt ($this->ch, CURLOPT_POSTFIELDS, http_build_query($postdata));

    $result=curl_exec($this->ch);
    $headers = curl_getinfo($this->ch);

    if ($headers['url'] == $this->options['url']) {
        die("Cannot login.");
    }

    curl_setopt($this->ch, CURLOPT_POST, 0);

    $this->getCSRFToken();
  }

  function getCSRFToken () {
    curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/session/token");
    $this->CSRFToken = curl_exec($this->ch);
    $this->sessionHeaders[] = "X-CSRF-Token: {$this->CSRFToken}";
  }

  function loadRestExport ($path, $options = array()) {
    $total = array();

    $options['paginated'] = array_key_exists('paginated', $options) ? $options['paginated'] : true;

    $page = $options['startPage'] ?? 0;
    do {
      $sep = strpos($path, '?') === false ? '?' : '&';
      print "{$this->options['url']}{$path}{$sep}page={$page}&_format=json\n";
      curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}{$path}{$sep}page={$page}&_format=json");
      curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);

      $result = curl_exec($this->ch);
      if (!$result || $result[0] !== '[') {
        throw new Exception("Error loading: " . $result);
      }

      $result = json_decode($result, true);

      if (!$result || !sizeof($result)) {
        $result = array();
      }

      foreach ($result as $e) {
        yield $e;
      }

      $page++;
    } while($options['paginated'] && sizeof($result) > 0);
  }

  function nodeGet ($id, $options = array()) {
    curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/node/{$id}?_format=json");
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($this->ch);
    if ($result[0] !== '{') {
      throw new Exception("Error loading '/node/{$id}': " . $result);
    }

    $result = json_decode($result, true);

    if (!array_key_exists('nid', $result)) {
      throw new Exception("Error loading '/node/{$id}'");
    }

    if (!$result || !sizeof($result)) {
      return null;
    }

    if (isset($options['embed_field'])) {
      foreach($options['embed_field'] as $field) {
        foreach ($result[$field] as $index => $ref) {
          $result[$field][$index]['data'] = $this->nodeGet($ref['target_id']);
        }
      }
    }

    return $result;
  }

  function nodeSave ($nid, $content) {
    $current_node = null;

    if ($nid) {
      $current_node = $this->nodeGet($nid);
    }

    // check for embedded fields
    foreach ($content as $field_id => $_d1) {
      foreach ($_d1 as $index => $_d2) {
        if (array_key_exists('data', $_d2)) {
          if ($current_node && array_key_exists($index, $current_node[$field_id])) {
            $current_embedded = $this->nodeGet($current_node[$field_id][$index]['target_id']);

            if (drupal_diff($_d2['data'], $current_embedded)) {
              $this->nodeSave($current_node[$field_id][$index]['target_id'], $_d2['data']);
            }

            $content[$field_id][$index] = ['target_id' => $current_node[$field_id][$index]['target_id']];
          }
          else {
            $_node = $this->nodeSave(null, $_d2['data']);
            $content[$field_id][$index] = ['target_id' => $_node['nid'][0]['value']];
          }

        }
      }
    }

    if ($nid) {
      curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/node/{$nid}?_format=json");
      curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    }
    else {
      curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/node?_format=json");
      curl_setopt($this->ch, CURLOPT_POST, true);
    }

    curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($content));
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, array_merge([
      'Content-type: application/json',
    ], $this->sessionHeaders));
    if (array_key_exists('verbose', $this->options) && $this->options['verbose']) {
      print(json_encode($content, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }

    $result = curl_exec($this->ch);
    if ($result[0] !== '{') {
      throw new Exception("Error saving node: " . $result);
    }

    $result = json_decode($result, true);

    if (!array_key_exists('nid', $result)) {
      throw new Exception($result['message']);
    }

    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, null);
    curl_setopt($this->ch, CURLOPT_POST, false);

    return $result;
  }

  function userGet ($id, $options = array()) {
    curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/user/{$id}?_format=json");
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($this->ch);
    if ($result[0] !== '{') {
      throw new Exception("Error loading '/user/{$id}': " . $result);
    }

    $result = json_decode($result, true);

    if (!array_key_exists('uid', $result)) {
      throw new Exception("Error loading '/user/{$id}': " . $result['message']);
    }

    if (!$result || !sizeof($result)) {
      return null;
    }

    return $result;
  }

  function userSave ($nid, $content) {
    $current_node = null;

    if ($nid) {
      curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/user/{$nid}?_format=json");
      curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    }
    else {
      curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/entity/user?_format=json");
      curl_setopt($this->ch, CURLOPT_POST, true);
    }

    curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($content));
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, array_merge([
      'Content-type: application/json',
    ], $this->sessionHeaders));
    if (array_key_exists('verbose', $this->options) && $this->options['verbose']) {
      print(json_encode($content, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }

    $result = curl_exec($this->ch);
    if ($result[0] !== '{') {
      throw new Exception("Error saving '/user/{$nid}': " . $result);
    }

    $result = json_decode($result, true);

    if (!array_key_exists('uid', $result)) {
      throw new Exception("Error saving '/user/{$nid}': " . $result['message']);
    }

    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, null);
    curl_setopt($this->ch, CURLOPT_POST, false);

    return $result;
  }

  function nodeRemove ($nid) {
    curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/node/{$nid}?_format=json");
    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, array_merge([
      'Content-type: application/json',
    ], $this->sessionHeaders));

    $result = curl_exec($this->ch);

    return true;
  }

  function paragraphSave ($id, $content) {
    $current_node = null;

    if ($id) {
      curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/entity/paragraph/{$id}?_format=json");
      curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    }
    else {
      curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/entity/paragraph?_format=json");
      curl_setopt($this->ch, CURLOPT_POST, true);
    }

    curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($content));
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, array_merge([
      'Content-type: application/json',
    ], $this->sessionHeaders));
    if (array_key_exists('verbose', $this->options) && $this->options['verbose']) {
      print(json_encode($content, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }

    $result = curl_exec($this->ch);
    if ($result[0] !== '{') {
      throw new Exception("Error saving paragraph/$id: " . $result);
    }

    $result = json_decode($result, true);

    if (!array_key_exists('id', $result)) {
      throw new Exception("Error saving paragraph/$id: " . $result['message']);
    }

    return $result;
  }

  function paragraphGet ($id) {
    $current_node = null;

    curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/entity/paragraph/{$id}?_format=json");
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, [
      'Content-type: application/json',
    ]);

    $result = curl_exec($this->ch);
    if ($result[0] !== '{') {
      throw new Exception("Error loading paragraph/$id: " . $result);
    }

    $result = json_decode($result, true);

    if (!array_key_exists('id', $result)) {
      throw new Exception("Error loading paragraph/$id: " . $result['message']);
    }

    return $result;
  }

  function paragraphRemove ($id) {
    $current_node = null;

    curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/entity/paragraph/{$id}?_format=json");
    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, array_merge([
      'Content-type: application/json',
    ], $this->sessionHeaders));

    $result = curl_exec($this->ch);

    return true;
  }

  /**
   * entityPath: entity_type_id/bundle/field_name
   */
  function fileUpload ($file, $entityPath) {
    if (!is_array($file)) {
      $file = array(
        'filename' => $file,
      );
    }

    if (!array_key_exists('content', $file)) {
      $file['content'] = file_get_contents($file['filename']);

      if ($file['content'] === false) {
        fwrite(STDERR, "Could not open file {$file['filename']}\n");
        exit(1);
      }
    }

    curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/file/upload/{$entityPath}?_format=json");
    curl_setopt($this->ch, CURLOPT_POST, true);
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, array_merge([
      'Content-type: application/octet-stream',
      "Content-Disposition: file; filename=\"{$file['filename']}\""
    ], $this->sessionHeaders));
    curl_setopt($this->ch, CURLOPT_POSTFIELDS, $file['content']);

    $result = curl_exec($this->ch);
    if ($result[0] !== '{') {
      throw new Exception("Error uploading file: " . $result);
    }

    $result = json_decode($result, true);

    if (!array_key_exists('fid', $result)) {
      throw new Exception("Error uploading file: " . $result['message']);
    }

    return $result;
  }

  function get ($url) {
    curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}{$url}");
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);

    return curl_exec($this->ch);
  }

  function download ($url, $file) {
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, 0);
    curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}{$url}");
    $fp = fopen($file, 'w+');
    curl_setopt($this->ch, CURLOPT_FILE, $fp);

    $result = curl_exec($this->ch);
    fclose($fp);

    return $result;
  }

  function entitySave ($entity, $id, $content) {
    global $drupalEntityConf;
    $e = $drupalEntityConf[$entity];

    $current_node = null;

    if ($id) {
      curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/" . strtr($e['entityHandle'], ['%' => $id]) . "?_format=json");
      curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    }
    else {
      $urlPart = strtr($e['createHandle'], ['%' => $id]);
      curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/{$urlPart}?_format=json");
      curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'POST');
      curl_setopt($this->ch, CURLOPT_POST, true);
    }

    curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($content));
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, array_merge([
      'Content-type: application/json',
    ], $this->sessionHeaders));
    if (array_key_exists('verbose', $this->options) && $this->options['verbose']) {
      print(json_encode($content, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }

    $result = curl_exec($this->ch);

    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, null);
    curl_setopt($this->ch, CURLOPT_POST, false);

    if ($result[0] !== '{') {
      throw new Exception("Error saving {$entity}/{$id}: " . $result);
    }

    $result = json_decode($result, true);

    if (!array_key_exists($drupalEntityConf[$entity]['idField'], $result)) {
      throw new Exception("Error saving {$entity}/{$id}: " . $result['message']);
    }

    return $result;
  }

  function entityGet ($entity, $id) {
    global $drupalEntityConf;
    $e = $drupalEntityConf[$entity];

    $current_node = null;

    curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/" . strtr($e['entityHandle'], ['%' => $id]) . "?_format=json");
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, array_merge([
      'Content-type: application/json',
    ], $this->sessionHeaders));

    $result = curl_exec($this->ch);
    if ($result[0] !== '{') {
      throw new Exception("Error loading {$entity}/{$id}: " . $result);
    }

    $result = json_decode($result, true);

    if (!array_key_exists($e['idField'], $result)) {
      throw new Exception("Error loading {$entity}/{$id}: " . $result['message']);
    }

    return $result;
  }

  function entityRemove ($entity, $id) {
    global $drupalEntityConf;
    $e = $drupalEntityConf[$entity];

    $current_node = null;

    curl_setopt($this->ch, CURLOPT_URL, "{$this->options['url']}/" . strtr($e['entityHandle'], ['%' => $id]) . "?_format=json");
    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, array_merge([
      'Content-type: application/json',
    ], $this->sessionHeaders));

    $result = curl_exec($this->ch);

    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, null);
    curl_setopt($this->ch, CURLOPT_POST, false);

    $status = curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
    if ($result === '') {
      return true;
    }

    $r = json_decode($result, true);
    if (array_key_exists('message', $r)) {
      throw new Exception("Error removing {$entity}/{$id}: " . $r['message']);
    }

    throw new Exception("Error removing {$entity}/{$id}:\n" . $result);
  }

  function taxonomyRemove ($id) {
    return $this->entityRemove('taxonomy', $id);
  }

  function taxonomyGet ($id) {
    return $this->entityGet('taxonomy', $id);
  }

  function taxonomySave ($id, $content) {
    return $this->entitySave('taxonomy', $id, $content);
  }

  function mediaRemove ($id) {
    return $this->entityRemove('media', $id);
  }

  function mediaGet ($id) {
    return $this->entityGet('media', $id);
  }

  function mediaSave ($id, $content) {
    return $this->entitySave('media', $id, $content);
  }

  function fileRemove ($id) {
    return $this->entityRemove('file', $id);
  }

  function fileGet ($id) {
    return $this->entityGet('file', $id);
  }

  function fileSave ($id, $content) {
    return $this->entitySave('file', $id, $content);
  }
}
