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

define('DEBUG', false);

class FunctionDef
{
	public $symbol;
	public $opts;
	public $type;
}

class InternalFunctionDef extends FunctionDef
{
	public $fn;
	public $argc;

	public function callFn($ctx)
	{
		$sn = $ctx->stackGetTop();

		return call_user_func($this->fn, 
			array('ctx' => $ctx, 'symbol' => $this->symbol, 'args' => $sn->args));
	}
}

class UserFunctionDef extends FunctionDef
{
	public $params;
	public $body_list;

	public function callFn($ctx)
	{
		$sn = $ctx->stackGetTop();

		$arg_map = array();
		foreach ($this->params as $key => $p)
		{
			if (isset($sn->args[$key]))
				$arg_map[$p] = $sn->args[$key];
		}


		$body_list = $this->body_list;
		foreach ($arg_map as $key => $val)
		{
			$body_list = preg_replace('/'.preg_quote(' '.$key.' ').'/', ' '.$val.' ', $body_list);
			$body_list = preg_replace('/'.preg_quote('('.$key.' ').'/', '('.$val.' ', $body_list);
			$body_list = preg_replace('/'.preg_quote(' '.$key.')').'/', ' '.$val.')', $body_list);
		}

		$r = process_statement($ctx, $body_list);
		if ($r === false)
		{
			err_log('Error, process_statement failed with list: %s', $body_list);
		}

		return $r;
	}
}

class StackNode
{
        public $args;
        public $vars;

	public function __construct($args = array())
	{
		$this->args = $args;
		$this->vars = array();
	}
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

	public function getFunctionDef($symbol)
	{
		$fn = null;

		if (isset($this->internal_fns[$symbol]))
			$fn = $this->internal_fns[$symbol];

		if (isset($this->user_fns[$symbol]))
			$fn = $this->user_fns[$symbol];

		return $fn;
	}

	public function addInternalFn($symbol, $fn_cb, $argc, $opts = array())
	{
		$fn_d = new InternalFunctionDef();

		$fn_d->symbol = $symbol;
		$fn_d->fn = $fn_cb;
		$fn_d->opts = $opts;
		$fn_d->type = 'internal';

		$this->internal_fns[$symbol] = $fn_d;
	}

	public function addUserFn($symbol, $params, $body_list)
	{
		$fn_d = new UserFunctionDef();

		$fn_d->symbol = $symbol;
		$fn_d->params = $params;
		$fn_d->body_list = $body_list;
		$fn_d->type = 'user';

		$this->user_fns[$symbol] = $fn_d;
	}
}
	 
function __fn_plus($args)
{ 
	$ctx = $args['ctx'];
	$symbol = $args['symbol'];
	$args = $args['args'];

	if (count($args) != 2)
	{
		err_log('Error, incorrect number of args in +: %s', print_r($args, true));

		return false;
	}

        return array_sum($args);
}

function __fn_print($args)
{ 
	$ctx = $args['ctx'];
	$symbol = $args['symbol'];
	$args = $args['args'];

	if (count($args) != 1)
	{
		err_log('Error, incorrect number of args in print: %s', print_r($args, true));

		return false;
	}

        echo $args[0]."\n";

	return null;
}

function __fn_defun($args)
{ 
	$ctx = $args['ctx'];
	$symbol = $args['symbol'];
	$args = $args['args'];

	if (count($args) != 3)
	{
		err_log('Error, incorrect number of args in defun: %s', print_r($args, true));

		return false;
	}

	$userfn_sym = $args[0];
	$params = get_components($args[1]);
	$fn_body = $args[2];

	$ctx->addUserFn($userfn_sym, $params, $fn_body);
}

function __fn_let($args)
{
	$ctx = $args['ctx'];
	$symbol = $args['symbol'];
	$args = $args['args'];

	if (count($args) != 2)
	{
		err_log('Error, incorrect number of arguments: %s', print_r($args, true));

		return false;
	}

	$binds = get_components($args[0]);
	foreach ($binds as $key => $b)
	{
		$variable = get_components($b);

		if (count($variable) != 2)
		{
			err_log('Error, incorrect variable bind: %s', print_r($variable, true));

			return false;
		}

		$c = get_components($variable[1]);
		if (count($c) > 1)
		{
			$r = process_statement($ctx, $variable[1]);

			$binds[$key] = create_list(array($variable[0], $r));
		}
	}

        $body_list = $args[1];

        foreach ($binds as $bind)
        {
		$b = get_components($bind);

		$key = $b[0];
		$val = $b[1];

                $body_list = preg_replace('/'.preg_quote(' '.$key.' ').'/', ' '.$val.' ', $body_list);
                $body_list = preg_replace('/'.preg_quote('('.$key.' ').'/', '('.$val.' ', $body_list);
                $body_list = preg_replace('/'.preg_quote(' '.$key.')').'/', ' '.$val.')', $body_list);
        }

	$r = process_statement($ctx, $body_list);
	if ($r === false)
	{
		err_log('Error, process_statement failed with list: %s', $body_list);
	}

        return $r;
}

function call_function($ctx, $components = array())
{
	if (count($components) == 0)
	{
		return false;
	}

	$fn_symbol = $components[0];

	$fn = $ctx->getFunctionDef($fn_symbol);
	if (!$fn)
	{
		err_log('Error, function symbol %s is not defined', $fn_symbol);

		return false;
	}

	dbg_log('call_function "%s" with components: %s', $fn_symbol, print_r($components, true));

	$args = array_slice($components, 1);

	if (!isset($fn->opts['no_arg_eval']) || !$fn->opts['no_arg_eval'])
	{
		foreach ($args as $key => $a)
		{
			$comps = get_components($a);

			$sym = reset($comps);
			if ($sym)
			{
				$a_fn = $ctx->getFunctionDef($sym);
				if ($a_fn)
				{
					$a_args = array_slice($comps, 1);

					$r = call_function($ctx, $comps);

					dbg_log('call_function result: %s', $r);

					$args[$key] = $r;

					dbg_log('arguments after replacing: %s ', print_r($args, true));
				}
			}
		}
	}

	dbg_log('actually calling "%s" with args %s', $fn_symbol, print_r($args, true));

	$n = new StackNode($args);

	$ctx->stackPush($n);

	$r = $fn->callFn($ctx);

	$ctx->stackPop($n);

	return $r;
}

function process_statement(&$context, $stm)
{
	dbg_log('Begin to process statement: "%s"', $stm);

	if (!$stm)
		return false;

	$comps = get_components($stm);
	if (count($comps) >= 2)
	{
		$r = call_function($context, $comps);
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

function create_list($components)
{
	return '('.implode(' ', $components).')';
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


function get_components($stm)
{
	$components = array();
	$current = '';
	$p_count = 0;

	if ($stm[0] != '(' || $stm[strlen($stm) - 1] != ')')
	{
		dbg_log('Statement "%s" is not a list', $stm);

		return array($stm);
	}

	$stm = substr($stm, 1, -1);
	for ($i = 0; $i < strlen($stm); $i++)
	{
                if (trim($stm[$i]) == '')
		{
			if ($current && $p_count == 0)
			{
				$components[] = $current;
				$current = '';

				continue;
			}
		}

		if ($stm[$i] == '(')
		{
			$p_count++;
		}

		if ($stm[$i] == ')')
		{
			$p_count--;

			if ($p_count == 0 && $current)
			{
				$current .= $stm[$i];
				$components[] = $current;;
				$current = '';

				continue;
			}
		}

		if (!$current && trim($stm[$i]) == '')
			continue;
		
		$current .= $stm[$i];
	}

	if ($current)
		$components[] = $current;

	return $components;
}

function execute()
{
	$ctx = new LispContext();

	$ctx->addInternalFn('defun', '__fn_defun', 4, array('no_arg_eval' => true));
	$ctx->addInternalFn('+', '__fn_plus', 3);
	$ctx->addInternalFn('print', '__fn_print', 2);
	$ctx->addInternalFn('let', '__fn_let', 3, array('no_arg_eval' => true));

        $filename = 'testscript.lisp';

        $ctx->stackPush(new StackNode());

        $src = read_src_file($filename);
        if ($src === false)
        {
                err_log('Error loading the source file: %s', $filename);

                return false;
        }

	$stms = get_components('('.$src.')');
        foreach ($stms as $s)
        {
                if (!preg_match('/^[\s]*$/', $s))
                {
                        $stm = clean($s);

                        $r = process_statement($ctx, $s);

                        dbg_log('Statement returned: %s', $r);
                }
        }

        return true;
}

date_default_timezone_set('Europe/Helsinki');

execute();

