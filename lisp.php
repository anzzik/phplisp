<?php

class InternalFunctionDef
{
	public $symbol;
	public $fn;
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

	public function addInternalFn($symbol, $fn_cb)
	{
		$fn_d = new InternalFunctionDef();

		$fn_d->symbol = $symbol;
		$fn_d->fn = $fn_cb;

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
			echo 'Error: function call syntax incorrect in '.$list."\n";

			return false;
		}

		$arg_list = '('.substr($list, strlen($matches[0]));

		$params = list_explode($fn_d->param_list);
		$args = list_explode($arg_list);

		$args = array_combine($params, $args);

		$body_list = $fn_d->body_list;

		foreach ($args as $key => $val)
		{
			$body_list = preg_replace('/\s'.$key.'\s/', ' '.$val.' ', $body_list);
			$body_list = preg_replace('/\('.$key.'\s/', '('.$val.' ', $body_list);
			$body_list = preg_replace('/\s'.$key.'\)/', ' '.$val.')', $body_list);
		}

		return process_list($this, $body_list);
	}
}

function list_explode($list)
{
	return explode(" ", substr($list, 1, -1));
}

function list_clean($list)
{
	$matches = array();

	$list = preg_replace('/[\s]+/', ' ', $list);
	$list = preg_replace('/^[\s]/', '', $list);
	$list = preg_replace('/[\s]$/', '', $list);

	$list = preg_replace('/\([\s]/', '(', $list);
	$list = preg_replace('/[\s]\)/', ')', $list);

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
			$arg = preg_replace('/\('.$matches[1].'\)/', $r, $arg);

			continue;
		}

		break;
	}


	echo "print: ".$arg."\n";

	

	return 0;
}

function __fn_defun($args)
{ 
	$ctx = $args['ctx'];
	$list = $args['list'];

	$matches = array();

	$pattern = '/defun[\s]([^\s]+)[\s](\([^\)]*\))[\s]/';

	$r = preg_match($pattern, $list, $matches);
	if (count($matches) < 3)
	{ 
		echo 'Error: defun syntax incorrect in '.$list."\n";

		return false;
	}

	$userfn_sym = $matches[1];
	$arg_list = $matches[2];

	$fn_body = substr($list, strlen($matches[0]) + 1, -1);

	$ctx->addUserFn($userfn_sym, $list, $arg_list, $fn_body);
}

function get_fnsym_from_list($list)
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
		return false;

	echo "Start to process list ".$list."\n";

	$list = list_clean($list);

	$r = null;
	$matches = array();

	$fn_symbol = get_fnsym_from_list($list);

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

	return;
}

function execute()
{
	$ctx = new LispContext();

	$ctx->addInternalFn('defun', '__fn_defun');
	$ctx->addInternalFn('+', '__fn_plus');
	$ctx->addInternalFn('print', '__fn_print');

	$r = process_list($ctx, '(defun testfunction (a b c) (+ a b c))');
	$r = process_list($ctx, '(testfunction 6 7 2)');
	$r = process_list($ctx, '(print (testfunction 6 7 2))');
}

execute();


