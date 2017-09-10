<?php

/*

This file is part of ctoolbox library collection. 
Copyright (C) 2017 Anssi Kulju 

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

define('DEBUG', true);

class InternalFunctionDef
{
	public $symbol;
	public $fn;
	public $opts;
}

class UserFunctionDef
{
	public $symbol;
	public $def_list;
	public $param_list;
	public $body_list;
}

class StackNode
{
        public $args = array();
        public $vars = array();
}

class LispContext 
{
	public $internal_fns = array();
	public $user_fns = array();
	public $global_vars = array();
        public $stack = array();

        public function stackPush($node)
        {
                array_push($this->stack, $node);
        }

        public function stackPop()
        {
                return array_pop($this->stack);
        }

        public function stackGetTop()
        {
                $tmp = array_slice($this->stack, -1);
                if (count($tmp) > 0)
                        return reset($tmp);

                return null;
        }

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
		if (!$r)
		{
			dbg_log('Error: function call syntax incorrect in "%s"', $list);

			return false;
		}

		$arg_list = '('.substr($list, strlen($matches[0]));

		$params = list_explode($fn_d->param_list);
		$args = list_explode($arg_list);

		$args = array_combine($params, $args);
                if ($args === false)
                {
                        err_log('Error, argument count incorrect in %s', $list);

                        return false;
                }

		$body_list = $fn_d->body_list;

                $n = new StackNode();

                $n->args = $args;
                $n->vars = array();
                
		foreach ($n->args as $key => $val)
		{
			$body_list = preg_replace('/'.preg_quote(' '.$key.' ').'/', ' '.$val.' ', $body_list);
			$body_list = preg_replace('/'.preg_quote('('.$key.' ').'/', '('.$val.' ', $body_list);
			$body_list = preg_replace('/'.preg_quote(' '.$key.')').'/', ' '.$val.')', $body_list);
		}

                $this->stackPush($n);

                $stms = explode_statements(substr($body_list, 1, -1));
                foreach ($stms as $stm)
                {
                        $r = process_statement($this, $stm);
                        if ($r === false)
                        {
                                err_log('Error, process_statement failed with list: %s', $body_list);
                        }
                }

                $this->stackPop();

                return $r;
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
	$symbol = $args['symbol'];
	$list = $args['list'];
	$arg = '('.substr($list, strlen($symbol) + 2);

        $eval_stm = $arg;

        $r = preg_match('/\(([^\)]+)\)/', $eval_stm, $matches);
        if (!$r || count($matches) < 2)
        {
                err_log('Error in __fn_plus list evaluation: %s', $eval_stm);
        }

        $sum = array_sum(explode(' ', $matches[1]));

        return $sum;
}

function __fn_print($args)
{ 
	$ctx = $args['ctx'];
	$symbol = $args['symbol'];
	$list = $args['list'];
	$arg = substr($list, strlen($symbol) + 2, -1);

        $eval_stm = $arg;

        /*
	while (1)
	{ 
		$l = get_inner_list($eval_stm);

		if ($l && $l != $eval_stm)
		{
			$r = process_statement($ctx, $l);

			$eval_stm = preg_replace('/'.preg_quote($l).'/', $r, $eval_stm);
                        $eval_stm = clean($eval_stm);

                        continue;
                }

                $r = process_statement($ctx, $l);


                break;
	}
         */

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

	$fn_body = substr($list, strlen($matches[0]) + 1, -1);

	$ctx->addUserFn($userfn_sym, $list, $arg_list, $fn_body);
}

function __fn_let($args)
{
	$ctx = $args['ctx'];
	$symbol = $args['symbol'];
	$list = $args['list'];
	$arg_list = substr($list, strlen($symbol) + 2, -1);

        $arg_arr = explode_statements($arg_list);
        if (count($arg_arr) != 2)
        {
                err_log('Error in __fn_let: incorrect syntax in %s', $list);

                return false;
        }

        $let_arg_lists = explode_statements(substr($arg_arr[0], 1, -1));

        $let_args = array();
        foreach ($let_arg_lists as $l)
        {
                $tmp = explode(' ', substr($l, 1, -1));
                if (count($tmp) != 2)
                {
                        err_log('Error in __fn_let: incorrect syntax in variable definition (%s)', $l);

                        return false;
                }

                $let_args[$tmp[0]] = $tmp[1];
        }

        $body_list = $arg_arr[1];

        foreach ($let_args as $key => $val)
        {
                $body_list = preg_replace('/'.preg_quote(' '.$key.' ').'/', ' '.$val.' ', $body_list);
                $body_list = preg_replace('/'.preg_quote('('.$key.' ').'/', '('.$val.' ', $body_list);
                $body_list = preg_replace('/'.preg_quote(' '.$key.')').'/', ' '.$val.')', $body_list);
        }

	$stms = explode_statements(substr($body_list, 1, -1));
	foreach ($stms as $stm)
        {
		$r = process_statement($ctx, $stm);
		if ($r === false)
		{
			err_log('Error, process_statement failed with list: %s', $body_list);
		}
        }

        return $r;
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

        $pattern = '/^\(([^\(\)\s]+)\s/';

	$r = preg_match($pattern, $list, $matches);
	if (!$r)
        {
                dbg_log('Function symbol not found in %s', $list);

                return false;
        }

        return $matches[1];
}

function process_statement(&$context, $stm)
{
	dbg_log('Begin to process statement: "%s"', $stm);

	if (!$stm)
		return false;

        $eval_stm = $stm;
	while (1)
	{ 
		$l = get_inner_list($eval_stm);

		if ($l && $l != $eval_stm)
		{
			$r = process_statement($context, $l);

			$eval_stm = preg_replace('/'.preg_quote($l).'/', $r, $eval_stm);
                        $eval_stm = clean($eval_stm);

			dbg_log('Statement evald: %s -> %s', $stm, $eval_stm);

                        continue;
                }

                break;
	}

        $stm = $eval_stm;

        $s_node = $context->stackGetTop();
        if (!$s_node)
        {
                err_log('Stack not initiated');
                
                return false;
        }

	$fn_symbol = get_fn_sym_from_list($stm);

	$fn = $context->getInternalFn($fn_symbol);
	if ($fn)
	{
		$r = $context->callInternalFn($fn_symbol, $stm);

		return $r;
	}

	$fn = $context->getUserFn($fn_symbol);
	if ($fn)
	{
		$r = $context->callUserFn($fn_symbol, $stm);

		return $r;
	}

        return false;
}

function err_log($fmt)
{
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

function dbg_log($fmt)
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

function read_src_file($filename)
{
        $fp = fopen($filename, 'r');
        if (!$fp)
        {
                err_log('File not found: %s', $filename);

                return false;
        }

        $r = file_get_contents($filename);
        if ($r === false)
        {
                err_log('File not found: %s', $filename);

                return false;
        }

        dbg_log('File %s loaded', $filename);

        return $r;
}

function explode_statements($src)
{
        $ready_for_end = true;
        $starting_par = false;
        $par_c = 0;
        $current = '';
        $statements = array();

        for ($i = 0; $i < strlen($src); $i++)
        {
                if (trim($src[$i]) == '')
                        $is_wspc = true;
                else
                        $is_wspc = false;

                switch ($src[$i])
                {
                case '(':
                        if (!$starting_par)
                        {
                                $starting_par = true;
                        }

                        $ready_for_end = false;
                        $par_c++;

                        break;

                case ')':
                        $par_c--;

                        if ($par_c == 0)
                                $ready_for_end = true;

                        break;
                }

                if (!$starting_par && $src[$i] != '(' && !$is_wspc)
                {
                        err_log('Syntax error, no starting par found (%s)', $src[$i]);

                        return false;
                }

                if ($par_c < 0)
                {
                        err_log('Syntax error, par_c = -1');

                        return false;
                }

                $current .= $src[$i];

                if ($ready_for_end && $current)
                {
                        if (!preg_match('/^[\s]*$/', $current))
                                $statements[] = $current;

                        $current = '';
                }
        }

        if (!$ready_for_end)
        {
                err_log('Errors found in source with par_c %d', $par_c);

                return false;
        }

        dbg_log('No error found in source');

        return $statements;
}

function execute()
{
	$ctx = new LispContext();

	$ctx->addInternalFn('defun', '__fn_defun');
	$ctx->addInternalFn('+', '__fn_plus');
	$ctx->addInternalFn('print', '__fn_print');
	$ctx->addInternalFn('let', '__fn_let');

        $filename = 'testscript.lisp';

        $ctx->stackPush(new StackNode());

        $src = read_src_file($filename);
        if ($src === false)
        {
                err_log('Error loading the source file: %s', $filename);

                return false;
        }

        $stms = explode_statements($src);
        foreach ($stms as $s)
        {
                if (!preg_match('/^[\s]*$/', $s))
                {
                        $stm = clean($s);

			$fn_symbol = get_fn_sym_from_list($stm);

			if ($fn_symbol == 'defun')
			{
				$ctx->callInternalFn($fn_symbol, $stm);

				continue;
			}

			if ($fn_symbol == 'let')
			{
				$ctx->callInternalFn($fn_symbol, $stm);

				continue;
			}

                        $r = process_statement($ctx, $s);

                        dbg_log('Statement returned: %s', $r);
                }
        }

        return true;
}

date_default_timezone_set('Europe/Helsinki');

execute();

