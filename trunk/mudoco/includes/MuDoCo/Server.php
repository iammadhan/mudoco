<?php
/**
 * MuDoCo - A Multi Domain Cookie
 * 
 * Server side classes.
 * 
 * @author berliozdavid@gmail.com
 *
 */

require_once "MuDoCo/Storage/Nonce/Interface.php";
require_once "MuDoCo/Plugin/Interface.php";

/**
 * MuDoCo Server class.
 *
 */
class MuDoCo_Server {

  /**
   * Generate and register a nonce with a given client nonce.
   * 
   * @param $string $cnonce
   * 
   * @return string nonce
   */
  public function generateNonce($cnonce) {
    return $this->getNonceStorage()->register($cnonce);
  }
  
  /**
   * API system call
   * 
   * @param string $name
   * @param array $param
   * @param mixed $data
   * 
   * @return int code
   */
  public function apiSystem($name, $params, &$data) {
    switch ($name) {
      case 'nonce':
        if (!$this->assertParam('cn', $params)) return 1;
        $nonce = $this->generateNonce($params['cn']);
        $data = md5($params['cn'] . $nonce);
        return 0;
    }
    return -1;
  }
  
  /**
   * Asser that all params in $list are present in $params.
   * 
   * @param array|string $list
   * @param array $params
   * 
   * @return boolean
   */
  protected function assertParam($list, $params) {
    if (!is_array($list)) {
      $list = array($list);
    }
    foreach ($list as $p) {
      if (!isset($params[$p])) return false;
    }
    return true;
  }
  
  /**
   * Nonce storage adapter
   *
   * @var MuDoCo_Storage_Nonce_Interface
   */
  protected $_nonceStorage = null;
  
  public function setNonceStorage(MuDoCo_Storage_Nonce_Interface $adapter) {
    $this->_nonceStorage = $adapter;
  }
  
  /**
   * Returns the nonce storage adapter.
   * Instanciates the default storage adapter.
   *
   * @return MuDoCo_Storage_Nonce_Interface
   */
  public function getNonceStorage() {
    if (!$this->_nonceStorage) {
      $class = $this->getSessionStorageClass();
      require_once strtr($class, '_', '/') . ".php";
      $this->_nonceStorage = new $class;
    }
    return $this->_nonceStorage;
  }
  
  /**
   * Check if the given cnonce+hnonce is correct.
   * 
   * @param string $cnonce
   * @param string $hnonce
   * 
   * @return boolean
   */
  public function checkNonce($cnonce, $hnonce) {
    if($nonce = $this->getNonceStorage()->get($cnonce)) {
      return md5($cnonce . $nonce) == $hnonce;
    }
    return false;
  }
  
  /**
   * Display a 1x1 Gif beacon.
   */
  public function beacon() {
    header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
    
    header('Content-Type: image/gif');

    echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');
  
    flush();
  }
  
  /**
   * Call the XSS JS Callback with the given data.
   * 
   * @param mixed $data
   * @param int $code 0 for success
   * @param int $i used for simultaneous calls
   */
  public function xss($data, $code, $i) {
    header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
    header('Content-Type: text/javascript');
    
    $data = (object) array(
        'data' => $data,
        'code' => $code,
        'i' => $i,
        );
    
    echo "MuDoCo.me().xssAjaxCallback(".json_encode($data).");";
  
    flush();
  }

  /**
   * Return Json for API.
   *
   * @param mixed $data
   * @param int $code
   */
  public function api($data, $code) {
    header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
    header('Content-Type: text/javascript');
  
    $data = (object) array(
        'data' => $data,
        'code' => $code,
    );
  
    echo json_encode($data);
  
    flush();
  }  
 
  /**
   * Return the plugin class basename.
   * 
   * foo => Foo
   * foo-bar => FooBar
   * foo_bar => Foo_Bar
   *  
   * @param string $q
   * @return string
   */
  protected function getPluginBasename($q) {
    $words = explode('-', strtolower($q));
    array_walk($words, function(&$val, $key) {
      $val = ucfirst($val);
    });
    $words = explode('_', implode('', $words));
    array_walk($words, function(&$val, $key) {
      $val = ucfirst($val);
    });
    return implode('_', $words);
  }
   
  protected $_plugins = array();
  
  /**
   * Returns a plugin object.
   * 
   * @param string $tag
   * 
   * @return MuDoCo_Plugin_Interface
   */
  public function getPlugin($tag) {
    global $mudoco_conf;
    if (empty($this->_plugin[$tag])) {
      $basename = $this->getPluginBasename($tag);
      $class = 'MuDoCo_Plugin_' . $basename;
      if (!class_exists($class)) {
        // external plugin ?
        $file = $mudoco_conf['MUDOCO_SERVER_PLUGINS_DIR'] . '/' . strtr($basename, '_', '/') . '.php';
        if (is_file($file)) {
          include_once $file;
        }
        else {
          @include_once strtr($class, '_', '/') . ".php";
        }
      }
      if (class_exists($class)) {
        $this->_plugin[$tag] = new $class($this);
      }
    }
    return $this->_plugin[$tag];
  }
  
  /**
   * Trigger plugins init stuff.
   * 
   * @param string $mode api or xss
   * @param boolean $safe
   * @see $mudoco_conf['MUDOCO_SERVER_INIT']
   */
  public function init($mode, $safe = false) {
    global $mudoco_conf;
    if (isset($mudoco_conf['MUDOCO_SERVER_INIT']) && is_array($mudoco_conf['MUDOCO_SERVER_INIT'])) {
      foreach ($mudoco_conf['MUDOCO_SERVER_INIT'] as $tag) {
        if ($plugin = $this->getPlugin($tag)) {
          $plugin->init($mode, $safe);
        }
      }
    }
  }
  
  protected function getSessionStorageClass() {
    global $mudoco_conf;
    if (isset($mudoco_conf['MUDOCO_SERVER_NONCE_STORAGE_CLASS'])) {
      return $mudoco_conf['MUDOCO_SERVER_NONCE_STORAGE_CLASS'];
    }
    return 'MuDoCo_Storage_Nonce_Sqlite';
  }
  
}