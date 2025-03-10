<?php
/**
 * @author Phia
 * @version 1.0
 * @created 14-Jan-2015 9:05:14 AM
 *
 * Goals:
 *		The goal of this class is to prevent cross site scripting (XSS). Also, it helps filter data type such as integer, numeric,
 *		string, phone, date, & etc.
 *
 * Description: (Any add on please add description here too!)
 *		Convert & to &amp;
 *		Convert < to &lt;
 *		Convert > to &gt;
 *		Convert " to &quot;
 *		Convert ' to &#039;
 *		Convert / to &#x2F; <--- This one is not skipped.
 *
 * For more information about XSS prevention rules:
 * 		1. https://www.owasp.org/index.php/XSS_%28Cross_Site_Scripting%29_Prevention_Cheat_Sheet#XSS_Prevention_Rules_Summary
 *		2. https://www.owasp.org/index.php/XSS_Filter_Evasion_Cheat_Sheet
 */

class BlockXSS{
	/* NOTE: In PHP 5.6 and later, the default_charset configuration option is used as the default value. 
			 PHP 5.4 and 5.5 will use UTF-8 as the default. Earlier versions of PHP use ISO-8859-1.*/
	public static $encoding="UTF-8";
	public static $flag=ENT_QUOTES;
	public static $flag2=ENT_SUBSTITUTE;
	public static $double_encode=false;

	public static $allowableTags='<div><span><pre><p><br><hr><hgroup><h1><h2><h3><h4><h5><h6>
            <ul><ol><li><dl><dt><dd><strong><em><b><i><u>
            <img><a><abbr><address><blockquote><area><audio><video>
            <form><fieldset><label><input><textarea>
            <caption><table><tbody><td><tfoot><th><thead><tr>
            <iframe>';

	//Alpha, numeric, a few special characters
	public static $defaultWhitelistFilter="[^0-9a-zA-Z_. \-+]";
	//Blacklist words...
	public static $blacklist=array('javascript', 'script', 'FSCommand','onAbort','onActivate','onAfterPrint','onAfterUpdate','onBeforeActivate','onBeforeCopy'
		,'onBeforeCut','onBeforeDeactivate','onBeforeEditFocus','onBeforePaste','onBeforePrint','onBeforeUnload','onBeforeUpdate','onBegin','onBlur'
		,'onBounce','onCellChange','onChange','onClick','onContextMenu','onControlSelect','onCopy','onCut','onDataAvailable','onDataSetChanged'
		,'onDataSetComplete','onDblClick','onDeactivate','onDrag','onDragEnd','onDragLeave','onDragEnter'
		,'onDragOver','onDragDrop','onDragStart','onDrop','onEnd','onError','onErrorUpdate','onFilterChange','onFinish','onFocus','onFocusIn'
		,'onFocusOut','onHashChange','onHelp','onInput','onKeyDown','onKeyPress','onKeyUp','onLayoutComplete','onLoad'
		,'onLoseCapture','onMediaComplete','onMediaError','onMessage','onMouseDown','onMouseEnter','onMouseLeave','onMouseMove','onMouseOut'
		,'onMouseOver','onMouseUp','onMouseWheel','onMove','onMoveEnd','onMoveStart','onOffline','onOnline','onOutOfSync'
		,'onPaste','onPause','onPopState','onProgress','onPropertyChange','onReadyStateChange','onRedo','onRepeat','onReset'
		,'onResize','onResizeEnd','onResizeStart','onResume','onReverse','onRowsEnter','onRowExit','onRowDelete','onRowInserted'
		,'onScroll','onSeek','onSelect','onSelectionChange','onSelectStart','onStart','onStop','onStorage','onSyncRestored'
        ,'onSubmit','onTimeError','onTrackChange','onUndo','onUnload','onURLFlip','seekSegmentTime');

	/**
	 * Return: Void
	 */
	public function __construct() {  }

	/**
	 * Return: Void
	 */
	public function __destruct() { }

	/**
	 * Return: Void
	 */
	protected static function _debug($val) {
		$subject='Possible XSS Detected!';
		$sender='PHP Error Master <daniel@ayalavilla.com>';
		$headers ="From: " . $sender."\r\n";
		$headers.="MIME-Version: 1.0\r\n";
		$headers.="Content-Type: text/html; charset=iso-8859-1\r\n";
		$headers.="X-Priority: 1\r\n";
		$headers.="X-Mailer: PHP / ".phpversion()."\r\n";

		//Live server
		if(isset($_SERVER['REQUEST_METHOD']) && !preg_match("/kronos/i", $_SERVER["SERVER_NAME"]))
			$to='daniel@ayalavilla.com';
		//Local server or test environment
		else
			$to='daniel@ayalavilla.com';

		if(isset($_SERVER['REQUEST_METHOD'])) {
			$body="A possible XSS has been detected! Keyword: <b>$val</b> occurred in the file ".$_SERVER['SCRIPT_FILENAME']."<p>";
			$body.='REQUEST METHOD='.$_SERVER['REQUEST_METHOD'].'<br>
					REQUEST TIME='.$_SERVER['REQUEST_TIME'].'<br>
					QUERY STRING='.$_SERVER['QUERY_STRING'].'<br>
					HTTP REFERER='.$_SERVER['HTTP_REFERER'].'<br>
					HTTP USER_AGENT='.$_SERVER['HTTP_USER_AGENT'].'<br>
					REMOTE ADDR='.$_SERVER['REMOTE_ADDR'].'<br>
					SCRIPT FILENAME='.$_SERVER['SCRIPT_FILENAME'].'<br>
					REQUEST URI='.$_SERVER['REQUEST_URI'].'<p>';
			$message='<font color="#ff0000">'.$body.'</font>';
		}else
			$message="A possible XSS has been detected! Keyword: <b>$val</b><br>This is not from a standard browser!";
		//-------- Send email ---------
		mail($to, $subject, $message, $headers);
		//-------- Throw exception ---------
		throw new Exception("A possible XSS has been detected! An alert has been sent!");
    }

	/**
	 * Return: Bool
	 */
	public static function blacklistDetected($val) {
		foreach(self::$blacklist as $word) {
			if(preg_match("/$word/i", $val)) {
				//Blacklist word detected... Alert admin!
				self::_debug($val);
				return true;
			}
		}
		return false;
	}

	/**
	 * Return: String
	 * Description: Sanitize a value.
	 *				This will prepare the data to make it safe to render (non-HTML) and save.
	 *				It will convert the speical characters above to prevent XSS.
	 */
	public static function sanitize($val) {
		if(!empty($val) || $val =="0") {
			$val= htmlentities(trim($val), self::$flag | self::$flag2, self::$encoding, self::$double_encode);
			//Do a quick scan to see if there is any blacklist words... if any is found this alert admin.
			//NOTE: Blacklist is not that realiable but this will at least add another layer of protection.
			self::blacklistDetected($val);
			return $val;
		}
		return "";
    }

	/**
	 * Return: String/Array
	 * Description: Sanitize a value OR array.
	 */
	public static function sanitizes($data) {
		if(is_array($data)) {
			$tmp = array();
			foreach ($data as $key => $val) {
				if(is_array($val))		$tmp[$key] = self::sanitizes($val);
				else 					$tmp[$key] = self::sanitize($val);
			}
			return $tmp;
		} else {
			return self::sanitize($data);
		}
    }

	/**
	 * Return: String
	 * Description: Desanitize a value.
	 * IMPORTANT: 	Only use this function when you really need to render a HTML output and the input is coming from a trusted source.
	 *				This method should only be call AFTER it has been sanitized.
	 */
	public static function desanitize($val) {
		if(!empty($val)) {
			$val= html_entity_decode($val, self::$flag | self::$flag2, self::$encoding);
			return strip_tags($val, self::$allowableTags);	//Only allowable tags are converted back.
		}
		return "";
	}

	/**
	 * Return: String
	 * Description: Filter out any unwanted charcters out before saving it into the server or redering it.
	 */
	public static function filter($val, $type=NULL) {
		switch(strtoupper($type)) {
			case "ALPHA":
				$pattern = "[^a-zA-Z]";			//No space allow
				break;
			case "INTEGER":
				$pattern = "[^0-9\-]";			//No space & plus allow
				break;
			case "NUMERIC":
				$pattern = "[^0-9.\-]";			//No space & plus allow
				break;
			case "ALPHANUMERIC":
				$pattern = "[^a-zA-Z0-9.\-+]";	//No space allow
				break;
			case "PHONE":
				$pattern = "[^0-9.\-()]";			//No space allow
				break;
			case "EMAIL":
				$pattern = "[^a-zA-Z0-9!#$%&*+\-\/=?^_{|}~@.\[\]]"; //No space allow
				break;
			case "URL":
				$pattern = "[^a-zA-Z0-9$\-_.+!*(),{}|\\^~\[\]<>#%;\/\?:@&=]"; //No space allow
				break;
			case "DATE":
				$pattern = "[^0-9.\-\/]";		//No space allow
				break;
			default:
				$pattern = self::$defaultWhitelistFilter;
				break;
		}
		return preg_replace('/'.$pattern.'/', '', $val); 	//Remove everything that doesn't match the given regular expression
	}
}
?>