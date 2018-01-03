<?php
/*
 * Copyright (c) 2008 Rémi Bougard <rbougard@free.fr>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */

/* liste de vos domaines (sans les extensions) */
$vhosts = array('domaine1', 'domaine2', 'domaine3', 'domaine4');

/* domaine principal */
$maindomain = 'domaine1.fr';

/* identifiant/mot de passe */
$login = 'XXXX';
$password = 'XXXX';

/* Date à partir de laquelle vous souhaitez récupérer vos logs. */
$start = '01-09-2007';

/*
 * Répertoire "racine" qui va contenir tous les logs
 * (fichiers "globaux" + fichiers splittés par domaine.
 */
$root = 'logs';

/*
 * Répertoire dans lequel vont être enregistrés les logs "globaux"
 * (non-splittés), à partir de la racine.
 */
$alldir = 'backup';

/* Répertoire dans lequel vont être enregistés les "rapports" webalizer. */
$reportdir = 'reports';

/*
 * Commande pour lancer webalizer.
 * Sont ajoutés ensuite :
 * -p (mode incrémental)
 * -t "domaine"
 * -o "reportdir/domaine/"
 */
$webalizer = 'webalizer';

function
check_path($root, $paths)
{
	if (empty($root))
		exit(__FUNCTION__ . " - no root.");
	if (empty($paths))
		exit(__FUNCTION__ . " - no paths.");

	if (!file_exists($root)) {
		if (!@mkdir($root . $p, 0755)) {
			exit(__FUNCTION__ . ' - unable to init root path: '
			    . $root);
		}
	}

	foreach ($paths as $k => $path) {
		$p = $root . '/' . $path;
		if (file_exists($p))
			@chmod($p, 0755);
		else {
			if (!@mkdir($p, 0755))
				exit(__FUNCTION__ . ' - unable to init path: '
				    . $p);
			chmod($p, 0755);
		}
	}
	return $p;
}

function
getfile($url, $output, $login, $password)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_USERPWD, $login . ':' . $password);
	//curl_setopt($ch, CURLOPT_VERBOSE, 1);
	curl_setopt($ch, CURLOPT_FAILONERROR, 1);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	$buf = curl_exec($ch);
	curl_close($ch);
	if ($buf === FALSE) {
		echo 'not found', "\n";
		return FALSE;
	}

	$fdout = fopen($output, 'w');
	if (!$fdout) {
		echo 'cannot create file ', $output, "\n";
		return FALSE;
	}

	$ret = fwrite($fdout, $buf);
	fclose($fdout);

	if (!$ret) {
		echo 'cannot write data to file ', $output, "\n";
		return FALSE;
	}

	return TRUE;
}

function
process_file($input, $output, $vhost)
{
	echo 'create file ', $output, "\n";
	$fdout = gzopen($output, 'w');
	if (!$fdout) {
		echo 'cannot create file ', $output, "\n";
		return FALSE;
	}

	$lines = gzfile($input);
	if (!$lines) {
		echo 'cannot read file ', $input, "\n";
		return FALSE;
	}
	foreach ($lines as $n => $line) {
		$t = explode(' ', $line);
		if (empty($t[1]))
			continue;
		$host = $t[1];
		if (!strpos($t[1], $vhost))
			continue;

		$ret = gzwrite($fdout, $line);
		if (!$ret) {
			echo 'cannot write data to file ', $output, "\n";
			return FALSE;
		}
	}

	gzclose($fdout);

	return TRUE;
}

function
usage()
{
	echo 'usage: plog.php [-hcsa]', "\n";
	echo "\t", '-h    print this help', "\n";
	echo "\t", '-c    check for missing log(s) file(s)', "\n";
	echo "\t", '-s    split globals logs files to vhosts logs files', "\n";
	echo "\t", '-a    run webalyzer on vhosts logs files (incremental mode)', "\n";
}

$opt = getopt('hcsa');

if (isset($opt['h'])) {
	usage();
	exit(0);
}

$check_file = isset($opt['c']) ? TRUE : FALSE;
$split_file = isset($opt['s']) ? TRUE : FALSE;
$analyze_file = isset($opt['a']) ? TRUE : FALSE;

if (!$check_file && !$split_file && !$analyze_file) {
	echo "nothing to do.\n";
	usage();
	exit(0);
}

date_default_timezone_set('Europe/Paris');

check_path($root, array($alldir));

if ($check_file) {
	$start = explode('-', $start);
	$d = $start[0];
	$m = $start[1];
	$y = $start[2];

	/* yesterday */
	$date_stop = strtotime('-1 day', strtotime(date('d-m-Y')));
	$date_stop = date('d-m-Y', $date_stop);

	$urlbase = 'https://logs.ovh.net/' . $maindomain . '/';

	echo "checking for missing files...\n";

	$index_path = $root . '/' . $alldir . '/index';
	$fdindex = fopen($index_path, 'a');
	if (!$fdindex) {
		echo "cannot open/create index ", $index_path, "\n";
		return FALSE;
	}
	$index = file($index_path);

	$stop = 0;
	while (!$stop) {
		$nbdays = date('t', mktime(0, 0, 0, $m, 1, $y));
		for (; $d <= $nbdays; $d++) {
			$date = sprintf("%02d-%02d-%04d", $d, $m, $y);
			$path = $maindomain . '-' . $date . '.log.gz';

			if (!in_array($path . "\n", $index)) {
				if (!file_exists($root . '/' . $alldir . '/' . $path)) {
					echo 'file "', $path, '" is missing...';
					$url = sprintf("%slogs-%02d-%02d/%s", $urlbase, $m, $y, $path);
					$ret = getfile($url, $root . '/' . $alldir . '/' . $path, $login, $password);
					if ($ret)
						echo "downloaded\n";
				}
				if (!fwrite($fdindex, $path . "\n")) {
					echo "cannot write data to index ", $index_path, "\n";
					return FALSE;
				}
			}

			if ($date == $date_stop) {
				$stop = 1;
				break;
			}
		}
		if ($m == 12) {
			$m = '01';
			$y++;
		} else
			$m++;
		$d = '01';
	}
	fclose($fdindex);
	echo "files check OK\n";
}

if ($split_file) {
	check_path($root, $vhosts);

	echo "split files...\n";

	$backup_path = $root . '/' . $alldir;
	$fd = @opendir($backup_path);
	if (!$fd) {
		echo 'Unable to open backup directory.', "\n";
		exit(1);
	}
	while (($file = readdir($fd)) !== FALSE) {
		if ($file == '.' || $file == '..' || $file == 'index'
		    || !is_file($backup_path . '/' . $file))
			continue;
		$suffix = substr($file, (strlen($file) - 18));
		foreach ($vhosts as $k => $vhost) {
			$vhpath = $root . '/' . $vhost . '/';
			$vhfile = $vhpath . $vhost . $suffix;
			if (!file_exists($vhfile))
				process_file($backup_path . '/' . $file, $vhfile, $vhost);
		}
	}
	closedir($fd);

	echo "OK\n";
}

if ($analyze_file) {
	check_path($reportdir, $vhosts);

	echo "analyze files...\n";

	foreach ($vhosts as $k => $vhost) {
		$index_path = $reportdir . '/index.' . $vhost;

		$fdindex = fopen($index_path, 'a');
		if (!$fdindex) {
			echo "cannot open/create index ", $index_path, "\n";
			return FALSE;
		}

		$index = file($index_path);

		$vhpath = $root . '/' . $vhost . '/';
		$fd = @opendir($vhpath);
		if (!$fd) {
			echo 'Unable to open ', $vhpath, 'directory.', "\n";
			exit(1);
		}
		$files = array();
		while (($file = readdir($fd)) !== FALSE) {
			if ($file == '.' || $file == '..'
			    || !is_file($vhpath . '/' . $file)
			    || in_array($file . "\n", $index))
				continue;
			$t = explode('-', $file);
			$d = $t[1];
			$m = $t[2];
			$y = substr($t[3], 0, 4);

			$files[$file] = $y . $m . $d;
		}
		closedir($fd);

		natsort($files);
		foreach ($files as $file => $date) {
			echo 'process ', $file, "\n";

			$ret = 0;
			$output = '';
			exec($webalizer . ' -p -t "' . $vhost . '" -o "' . $reportdir . '/' . $vhost . '" ' . $vhpath . $file, $output, $ret);
			/*
			if ($ret) {
				print_r($output);
				echo 'ret:', $ret, "\n";
				exit();
			}
			*/


			if (!fwrite($fdindex, $file . "\n")) {
				echo "cannot write data to index ", $index_path, "\n";
				return FALSE;
			}
		}

		fclose($fdindex);
	}

	echo "OK\n";
}

?>
