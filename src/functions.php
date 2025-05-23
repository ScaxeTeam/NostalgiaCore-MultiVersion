<?php

if(!function_exists("cli_set_process_title")){
	function cli_set_process_title($title){
		if(ENABLE_ANSI === true){
			echo "\x1b]0;" . $title . "\x07";
			return true;
		}else{
			return false;
		}
	}
}


function dummy(){

}

function safe_var_dump($var, $cnt = 0){
	switch(true){
		case is_array($var):
			echo str_repeat("  ", $cnt) . "array(" . count($var) . ") {" . PHP_EOL;
			foreach($var as $key => $value){
				echo str_repeat("  ", $cnt + 1) . "[" . (is_integer($key) ? $key : '"' . $key . '"') . "]=>" . PHP_EOL;
				safe_var_dump($value, $cnt + 1);
			}
			echo str_repeat("  ", $cnt) . "}" . PHP_EOL;
			break;
		case is_integer($var):
			echo str_repeat("  ", $cnt) . "int(" . $var . ")" . PHP_EOL;
			break;
		case is_float($var):
			echo str_repeat("  ", $cnt) . "float(" . $var . ")" . PHP_EOL;
			break;
		case is_bool($var):
			echo str_repeat("  ", $cnt) . "bool(" . ($var === true ? "true" : "false") . ")" . PHP_EOL;
			break;
		case is_string($var):
			echo str_repeat("  ", $cnt) . "string(" . strlen($var) . ") \"$var\"" . PHP_EOL;
			break;
		case is_resource($var):
			echo str_repeat("  ", $cnt) . "resource() of type (" . get_resource_type($var) . ")" . PHP_EOL;
			break;
		case is_object($var):
			echo str_repeat("  ", $cnt) . "object(" . get_class($var) . ")" . PHP_EOL;
			break;
		case is_null($var):
			echo str_repeat("  ", $cnt) . "NULL" . PHP_EOL;
			break;
	}
}

function kill($pid){
	switch(Utils::getOS()){
		case "win":
			exec("taskkill.exe /F /PID " . ((int) $pid) . " > NUL");
			break;
		case "mac":
		case "linux":
		default:
			exec("kill -9 " . ((int) $pid) . " > /dev/null 2>&1");
	}
}

/**
 * @deprecated Use $a ?? $null
 */
function nullsafe(&$a, $null){
	return $a ?? $null;
}



function require_all($path, &$count = 0){
	$dir = dir($path . "/");
	$dirs = [];
	while(false !== ($file = $dir->read())){
		if($file !== "." and $file !== ".."){
			if(!is_dir($path . $file) and strtolower(substr($file, -3)) === "php"){
				require_once($path . $file);
				++$count;
			}elseif(is_dir($path . $file)){
				$dirs[] = $path . $file . "/";
			}
		}
	}
	foreach($dirs as $dir){
		require_all($dir, $count);
	}

}

function hard_unset(&$var){
	if(is_object($var)){
		$unset = new ReflectionClass($var);
		foreach($unset->getProperties() as $prop){
			$prop->setAccessible(true);
			@hard_unset($prop->getValue($var));
			$prop->setValue($var, null);
		}
		$var = null;
		unset($var);
	}elseif(is_array($var)){
		foreach($var as $i => $v){
			hard_unset($var[$i]);
		}
		$var = null;
		unset($var);
	}else{
		$var = null;
		unset($var);
	}
}

function arg($name, $default = false){
	global $arguments, $argv;
	if(!isset($arguments)){
		$arguments = arguments($argv);
	}

	return $arguments["commands"][$name] ?? $default;
}

function arguments($args){
	if(!is_array($args)){
		$args = [];
	}
	array_shift($args);
	$args = join(' ', $args);

	preg_match_all('/ (--[\w\-]+ (?:[= ] [^-\s]+ )? ) | (-\w+) | (\w+) /x', $args, $match);
	$args = array_shift($match);

	$ret = [
		'input' => [],
		'commands' => [],
		'flags' => []
	];

	foreach($args as $arg){

		// Is it a command? (prefixed with --)
		if(str_starts_with($arg, '--')){

			$value = preg_split('/[= ]/', $arg, 2);
			$com = substr(array_shift($value), 2);
			$value = join($value);

			$ret['commands'][$com] = !empty($value) ? $value : true;
			continue;

		}

		// Is it a flag? (prefixed with -)
		if(str_starts_with($arg, '-')){
			$ret['flags'][] = substr($arg, 1);
			continue;
		}

		$ret['input'][] = $arg;

	}

	return $ret;
}

function console($message, $EOL = true, $log = true, $level = 1){
	if(!defined("DEBUG") or DEBUG >= $level){
		$message .= $EOL === true ? PHP_EOL : "";
		$time = (ENABLE_ANSI === true ? FORMAT_AQUA . date("H:i:s") . FORMAT_RESET : date("H:i:s")) . " ";
		$replaced = TextFormat::clean(preg_replace('/\x1b\[[0-9;]*m/', "", $time . $message));
		if($log === true and (!defined("LOG") or LOG === true)){
			logg(date("Y-m-d") . " " . $replaced, "console", false, $level);
		}
		if(ENABLE_ANSI === true){
			$add = "";
			if(preg_match("/\[([a-zA-Z0-9]*)\]/", $message, $matches) > 0){
				$add .= match ($matches[1]) {
					"ERROR", "SEVERE" => FORMAT_RED,
					"INTERNAL", "DEBUG" => FORMAT_WHITE,
					"WARNING" => FORMAT_YELLOW,
					"NOTICE" => FORMAT_AQUA,
					default => FORMAT_GRAY,
				};
			}
			$message = TextFormat::toANSI($time . $add . $message . FORMAT_RESET);
		}else{
			$message = $replaced;
		}
		echo $message;
	}
}

function getTrace($start = 1){
	$e = new Exception();
	$trace = $e->getTrace();
	$messages = [];
	$j = 0;
	for($i = (int) $start; isset($trace[$i]); ++$i, ++$j){
		$params = "";
		foreach($trace[$i]["args"] as $name => $value){
			$params .= (is_object($value) ? get_class($value) . " " . (method_exists($value, "__toString") ? $value->__toString() : "object") : gettype($value) . " " . @strval($value)) . ", ";
		}
		$messages[] = "#$j " . ($trace[$i]["file"] ?? "") . "(" . ($trace[$i]["line"] ?? "") . "): " . (isset($trace[$i]["class"]) ? $trace[$i]["class"] . $trace[$i]["type"] : "") . $trace[$i]["function"] . "(" . substr($params, 0, -2) . ")";
	}
	return $messages;
}

function error_handler($errno, $errstr, $errfile, $errline){
	if(error_reporting() === 0){ //@ error-control
		return false;
	}
	$errorConversion = [
		E_ERROR => "E_ERROR",
		E_WARNING => "E_WARNING",
		E_PARSE => "E_PARSE",
		E_NOTICE => "E_NOTICE",
		E_CORE_ERROR => "E_CORE_ERROR",
		E_CORE_WARNING => "E_CORE_WARNING",
		E_COMPILE_ERROR => "E_COMPILE_ERROR",
		E_COMPILE_WARNING => "E_COMPILE_WARNING",
		E_USER_ERROR => "E_USER_ERROR",
		E_USER_WARNING => "E_USER_WARNING",
		E_USER_NOTICE => "E_USER_NOTICE",
		E_STRICT => "E_STRICT",
		E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
		E_DEPRECATED => "E_DEPRECATED",
		E_USER_DEPRECATED => "E_USER_DEPRECATED",
	];
	$errno = $errorConversion[$errno] ?? $errno;
	console("[ERROR] A " . $errno . " error happened: \"$errstr\" in \"$errfile\" at line $errline", true, true, 0);
	foreach(getTrace() as $i => $line){
		console("[TRACE] $line");
	}
	return true;
}

function logg($message, $name, $EOL = true, $level = 2, $close = false){
	global $fpointers, $dolog;
	if((!defined("DEBUG") or DEBUG >= $level) and (!defined("LOG") or LOG === true)){
		$message .= $EOL === true ? PHP_EOL : "";
		if(!isset($fpointers)){
			$fpointers = [];
		}
		if(!isset($fpointers[$name]) or $fpointers[$name] === false){
			$fpointers[$name] = @fopen(DATA_PATH . "/" . $name . ".log", "ab");
		}
		if($dolog){
			@fwrite($fpointers[$name], $message);
		}
		if($close === true){
			fclose($fpointers[$name]);
			unset($fpointers[$name]);
		}
	}
}

function release_lock(){
	if(LOCK_FILE !== null){
		if(!flock(LOCK_FILE, LOCK_UN)){
			console("[CRITICAL] Failed to release the server.lock file.");
		}
		if(!fclose(LOCK_FILE)){
			console("[CRITICAL] Could not close server.lock resource.");
		}
	} else {
		console("[CRITICAL] Failed to find the server.lock file.");
	}
}