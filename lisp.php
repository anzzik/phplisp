<?php

define('DEBUG', true);

class InternalFunctionDef
{
	public $symbol;
	public $fn;
	public $opts;

	public function getOption($opt)
	{
		if (!array_key_exists($opt, $this->opts))
			return null;

		return $this->opts[$opt];
	}
}

class UserFunctionDef
{
	public $symbol;
	public $def_list;
	public $param_list;
	public $body_list;
}

class LispContext 
{
	public $internal_fns;
	public $user_fns;
	public $vars;

	public function addInternalFn($symbol, $fn_cb, $opts = array())
	{
		$fn_d = new InternalFunctionDef();

		$fn_d->symbol = $symbol;
		$fn_d->fn = $fn_cb;
		$fn_d->opts = $opts;

		$this->internal_fns[$symbol] = $fn_d;
	}

	public function addUserFn($symbol, $def_list, $param_list, $body_list)
	{
		$fn_d = new UserFunctionDef();

		$fn_d->symbol = $symbol;
		$fn_d->def_list = $def_list;
		$fn_d->param_list = $param_list;
		$fn_d->body_list = $body_list;

		$this->user_fns[$symbol] = $fn_d;
	}

	public function getInternalFn($symbol)
	{
		if (isset($this->internal_fns[$symbol]))
			return $this->internal_fns[$symbol];

		return null;
	}

	public function getUserFn($symbol)
	{
		if (isset($this->user_fns[$symbol]))
			return $this->user_fns[$symbol];

		return null;
	}

	public function callInternalFn($symbol, $list)
	{
		if (!isset($this->internal_fns[$symbol]))
			return false;

		return call_user_func($this->internal_fns[$symbol]->fn, 
			array('ctx' => &$this, 'symbol' => $symbol, 'list' => $list));
	}

	public function callUserFn($symbol, $list)
	{
		if (!isset($this->user_fns[$symbol]))
			return false;

		$fn_d = $this->user_fns[$symbol];

		$matches = array();

		$pattern = '/\('.$symbol.'[\s]/';
		$r = preg_match($pattern, $list, $matches);
		if (count($matches) == 0)
		{
			dbg_log('Error: function call syntax incorrect in "%s"', $list);

			return false;
		}

		$arg_list = '('.substr($list, strlen($matches[0]));

		$params = list_explode($fn_d->param_list);
		$args = list_explode($arg_list);

		$args = array_combine($params, $args);

		$body_list = $fn_d->body_list;

		foreach ($args as $key => $val)
		{
			$body_list = preg_replace('/'.preg_quote(' '.$key.' ').'/', ' '.$val.' ', $body_list);
			$body_list = preg_replace('/'.preg_quote('('.$key.' ').'/', '('.$val.' ', $body_list);
			$body_list = preg_replace('/'.preg_quote(' '.$key.')').'/', ' '.$val.')', $body_list);
		}

		return process_statement($this, $body_list);
	}
}

function list_explode($list)
{
	return explode(" ", substr($list, 1, -1));
}

function clean($list)
{
	$matches = array();

	$list = preg_replace('/[\s]+/', ' ', $list);
	$list = preg_replace('/^\s/', '', $list);
	$list = preg_replace('/\s$/', '', $list);

	$list = preg_replace('/\(\s/', '(', $list);
	$list = preg_replace('/\s\)/', ')', $list);

	dbg_log('Statement cleaned: "%s"', $list);

	return $list;
}

function __fn_plus($args)
{ 
	$ctx = $args['ctx'];
	$list = $args['list'];

	$arr = explode(" ", $list);
	if (count($arr) > 1)
		return array_sum(array_slice($arr, 1));

	return 0;
}

function __fn_print($args)
{ 
	$ctx = $args['ctx'];
	$symbol = $args['symbol'];
	$list = $args['list'];

	$arg = substr($list, strlen($symbol) + 2, -1);

	while (1)
	{ 
		$matches = array();
		$pattern = '/\(([^\)]+)/';

		if (preg_match($pattern, $arg, $matches))
		{ 
			$r = process_list($ctx, '('.$matches[1].')');
			$arg = preg_replace('/'.preg_quote('('.$matches[1].')').'/', $r, $arg);

			continue;
		}

		break;
	}

	echo $arg."\n";

	return null;
}

function __fn_defun($args)
{ 
	$ctx = $args['ctx'];
	$symbol = $args['symbol'];
	$list = $args['list'];

	$matches = array();

	$pattern = '/'.preg_quote($symbol).'\s([^\s]+)\s(\([^\)]*\))\s/';

	$r = preg_match($pattern, $list, $matches);
	if (count($matches) < 3)
	{ 
		dbg_log('Error: defun syntax incorrect in "%s"', $list);

		return false;
	}

	$userfn_sym = $matches[1];
	$arg_list = $matches[2];

	$fn_body = substr($list, strlen($matches[0]) + 1);

	$ctx->addUserFn($userfn_sym, $list, $arg_list, $fn_body);
}

function get_inner_list($stm)
{
	if (!$stm)
		return false;

	$matches = array();
	$pattern = '/\(([^\(\)])+\)/';

	$r = preg_match($pattern, $stm, $matches);

	if ($r && count($matches) > 1)
	{
		return $matches[0];
	}

	return $stm;
}

function get_fn_sym_from_list($list)
{
	$matches = array();

	$pattern = '/\(([^\s]+)/';

	$r = preg_match($pattern, $list, $matches);
	if ($r !== false)
	{
		return $matches[1];
	}
}

function process_list(&$context, $list)
{
	if (!$list)
	{
		dbg_log('Error: empty list in process_list');
		return false;
	}

	dbg_log('Begin to process list: "%s"', $list);

	$r = null;
	$matches = array();

	$fn_symbol = get_fn_sym_from_list($list);

	$fn = $context->getInternalFn($fn_symbol);
	if ($fn)
	{
		$r = $context->callInternalFn($fn_symbol, $list);

		return $r;
	}

	$fn = $context->getUserFn($fn_symbol);
	if ($fn)
	{
		$r = $context->callUserFn($fn_symbol, $list);

		return $r;
	}

	dbg_log('Error: function not found in process_list: "%s"', $list);

	return false;
}

function process_statement(&$context, $stm)
{
	if (!$stm)
		return false;

	dbg_log('Begin to process statement: "%s"', $stm);

	$stm = clean($stm);

	$fn_symbol = get_fn_sym_from_list($stm);

	$fn = $context->getInternalFn($fn_symbol);
	if ($fn && $fn->getOption('no_eval'))
	{
		dbg_log('Evaluation skipped with "%s"', $stm);
		return process_list($context, $stm);
	}

	$eval_stm = $stm;
	while (1)
	{
		$list = get_inner_list($eval_stm);

		if ($list && $list != $eval_stm)
		{
			$r = process_list($context, $list);

			$eval_stm = preg_replace('/'.preg_quote($list).'/', $r, $eval_stm);
			$eval_stm = clean($eval_stm);
		}
		else
			break;
	}

	dbg_log('Evaluation done: "%s" evaluated to "%s"', $stm, $eval_stm);

	return process_list($context, $eval_stm);
}

function dbg_log($fmt )
{
	if (DEBUG == false)
		return;

	$bt = debug_backtrace();
	$argv = func_get_args();
	array_shift($argv);
	$string = vsprintf($fmt, $argv);

	$prefix = sprintf( "%s:%d: ",
		basename($bt[0]['file']),
		$bt[0]['line'] );

	$line = date('Y-m-d H:i:s').' '.$prefix.$string."\n";

	echo $line;
}

function execute()
{
	$ctx = new LispContext();

	$ctx->addInternalFn('defun', '__fn_defun', array('no_eval' => true));
	$ctx->addInternalFn('+', '__fn_plus');
	$ctx->addInternalFn('print', '__fn_print');

	$r = process_statement($ctx, '(defun test (a b) ((print 2) (+ a b 1))');
	$r = process_statement($ctx, '(print (+ (+ 3 (+ 5 6)) (+ 7 (+ (test 3 6) 7))))');

	dbg_log('Statement returned: %s', $r);
}

execute();

