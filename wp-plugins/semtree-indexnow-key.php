<?php
/**
 * Plugin Name: Semtree IndexNow Key
 * Description: Serwuje plik klucza IndexNow (/{klucz}.txt) dla zaplecza Semtree. Bez ustawień, bez zapisu do bazy.
 * Version: 1.0.0
 *
 * Instalacja: wgraj ten plik do wp-content/mu-plugins/ na każdej stronie zaplecza
 * (mu-pluginy ładują się same, nie trzeba ich aktywować). Klucz musi być taki sam jak
 * INDEXNOW_KEY w includes/indexnow.php aplikacji zaplecze.semtree.com.pl.
 * Sprawdzenie: https://twoja-domena.pl/f1abc58f9a9f293363eea23b4a693d1a.txt zwraca sam klucz.
 */

if (!defined('ABSPATH')) exit;

const SEMTREE_INDEXNOW_KEY = 'f1abc58f9a9f293363eea23b4a693d1a';

add_action('init', function () {
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($path !== '/' . SEMTREE_INDEXNOW_KEY . '.txt') return;
    status_header(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo SEMTREE_INDEXNOW_KEY;
    exit;
}, 0);
