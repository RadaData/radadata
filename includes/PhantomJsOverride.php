<?php

use JonnyW\PhantomJs\Client as PJClient;

class MyPJClient extends PJClient {
//  /**
//   * Client instance
//   *
//   * @var MyPJClient
//   */
//  private static $instance;
//
//  /**
//   * PhantomJs base wrapper
//   *
//   * @var string
//   */
//  protected $wrapper = <<<EOF
//
//	var page = require('webpage').create(),
//		response = {},
//		headers = %1\$s;
//
//	page.settings.resourceTimeout = %2\$s;
//	page.onResourceTimeout = function(e) {
//		response 		= e;
//		response.status = e.errorCode;
//	};
//
//	page.onResourceReceived = function (r) {
//		if(!response.status) response = r;
//	};
//
//	page.customHeaders = headers ? headers : {};
//
//	page.open('%3\$s', '%4\$s', '%5\$s', function(status) {
//        console.log(page.url + ' loaded, status = ' + status);
//        if (status === 'success')
//        {
//        	setTimeout(function(){
//				%6\$s
//				console.log(JSON.stringify(response, undefined, 4));
//				phantom.exit();
//        	}, 1000);
//        }
//        else {
//            console.log(JSON.stringify(response, undefined, 4));
//            phantom.exit();
//		}
//	});
//EOF;
//
//  /**
//   * PhantomJs screen capture
//   * command template
//   *
//   * @var string
//   */
//  protected $captureCmd = <<<EOF
//
//			page.render('%1\$s');
//
//			response.content = page.evaluate(function () {
//				return document.documentElement.outerHTML
//			});
//EOF;
//
//  /**
//   * PhantomJs page open
//   * command template
//   *
//   * @var string
//   */
//  protected $openCmd = <<<EOF
//
//			response.content = page.evaluate(function () {
//				return document.documentElement.outerHTML
//			});
//EOF;
//
//  public static function getInstance(FactoryInterface $factory = null)
//  {
//    if(!self::$instance instanceof ClientInterface) {
//      self::$instance = new MyPJClient($factory);
//    }
//
//    return self::$instance;
//  }
//  protected function parse($data)
//  {
//    // Data is invalid
//    if($data === null || !is_string($data)) {
//      return array();
//    }
//
//    // Not a JSON string
//    if(substr($data, 0, 1) !== '{') {
//      $data = preg_replace('|^[^{]*?{|', '{', $data);
//    }
//
//    // Return decoded JSON string
//    return (array) json_decode($data, true);
//  }
}
