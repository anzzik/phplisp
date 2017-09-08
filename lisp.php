<?php

class InternalFunctionDef
{
	public $symbol;
	public $fn;
	public $opts;

	public function getOption($opt)
	{
		if (!isset($this->opts[$opt]))
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
			echo 'body list: '.$body_list."\n";
			echo 'replacing: '.$key.' - '.$val."\n";
			$body_list = preg_replace('/'.preg_quote(' '.$key.' ').'/', ' '.$val.' ', $body_list);
			$body_list = preg_replace('/'.preg_quote('('.$key.' ').'/', '('.$val.' ', $body_list);
			$body_list = preg_replace('/'.preg_quote(' '.$key.')').'/', ' '.$val.')', $body_list);
			echo 'body list now: '.$body_list."\n";
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
	$list = preg_replace('/^\s/', '', $list);
	$list = preg_replace('/\s$/', '', $list);

	$list = preg_replace('/\(\s/', '(', $list);
	$list = preg_replace('/\s\)/', ')', $list);

	echo 'After cleaning: '.$list."\n";

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

	return 0;
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
		echo 'Error: defun syntax incorrect in '.$list."\n";

		return false;
	}

	$userfn_sym = $matches[1];
	$arg_list = $matches[2];

	$fn_body = substr($list, strlen($matches[0]) + 1);

	$ctx->addUserFn($userfn_sym, $list, $arg_list, $fn_body);
}

function get_inner_list($list)
{
	if (!$list)
		return false;

	$matches = array();
	$pattern = '/\(([^\(\)])+\)/';

	$r = preg_match($pattern, $list, $matches);

	if ($r && count($matches) > 1)
	{
		return $matches[0];
	}

	return $list;
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

function evaluate($stm)
{
	$result = '';
	$list = $stm;

	while (1)
	{
		$result = get_inner_list($list);
		if (!$result)
		{
			echo 'Error in evaluation with statement: '.$stm."\n";

			break;
		}

		if ($result == $list)
		{
			break;
		}

		$list = $result;

		echo '"'.$list.'" - "'.$result.'"'."\n";
	}

	return $result;
}

function process_list(&$context, $list)
{
	if (!$list)
	{
		echo 'Error: empty list in process_list'."\n";
		return false;
	}

	echo "Start to process list ".$list."\n";

	$list = list_clean($list);

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

	echo 'Error: function not found in process_list for list '.$list."\n";

	return false;
}

function process_statement(&$context, $stm)
{
	if (!$stm)
		return false;

	echo "Start to process statement ".$stm."\n";

	$r = false;

	$stm = list_clean($stm);

	$fn_symbol = get_fn_sym_from_list($stm);

	$fn = $context->getInternalFn($fn_symbol);
	if ($fn && $fn->getOption('no_eval'))
	{
		echo 'no_eval with '.$stm."\n";
		return process_list($context, $stm);
	}

	while (1)
	{
		$list = get_inner_list($stm);

		if ($list && $list != $stm)
		{
			echo 'Sublist start with '.$list."\n";
			$r = process_list($context, $list);
			echo 'List processed: '.$list."\n";
			$tmp = $stm;
			$stm = preg_replace('/'.preg_quote($list).'/', $r, $stm);

			echo $tmp.' --> '.$stm."\n";
		}
		else
			break;
	}

	echo 'Eval done'."\n";

	return process_list($context, $stm);
}

function execute()
{
	$ctx = new LispContext();

	$ctx->addInternalFn('defun', '__fn_defun', array('no_eval' => true));
	$ctx->addInternalFn('+', '__fn_plus');
	$ctx->addInternalFn('print', '__fn_print');

	$r = process_statement($ctx, '(defun test (a b) (+ a b 1)');
	$r = process_statement($ctx, '(print (+ (+ 3 (+ 5 6)) (+ 7 (+ (test 3 6) 7))))');

	echo 'Statement returned: '.$r."\n";
}

execute();

