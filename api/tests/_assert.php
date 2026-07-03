<?php
$GLOBALS['_t']=0; $GLOBALS['_f']=0;
function ok($c,$m){ $GLOBALS['_t']++; if($c){echo "  PASS: $m\n";} else {$GLOBALS['_f']++; echo "  FAIL: $m\n";} }
function eq($a,$b,$m){ ok($a===$b, $m." [attendu ".var_export($b,true).", obtenu ".var_export($a,true)."]"); }
function done(){ $p=$GLOBALS['_t']-$GLOBALS['_f']; echo "\n$p/{$GLOBALS['_t']} OK\n"; exit($GLOBALS['_f']?1:0); }
