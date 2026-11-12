<?php
declare(strict_types=1);
define('ITTAI_DIRECTORY_MODE',true);
if(!defined('APP_ROOT')){for($d=__DIR__,$x=0;$x<7;$x++,$d=dirname($d)){if(is_file($d.'/app/Core/paths.php')){require_once $d.'/app/Core/paths.php';break;}}}
require_once APP_ROOT.'/Core/config.php';require_once APP_ROOT.'/Modules/Tools/Services/SavedNumbersService.php';require_once __DIR__.'/layout.php';if(session_status()!==PHP_SESSION_ACTIVE){session_name('ittai_tools');session_start();}if(empty($_SESSION['tool_csrf']))$_SESSION['tool_csrf']=bin2hex(random_bytes(24));
$emailOn=defined('ITTAI_MAIL_FROM')&&(string)ITTAI_MAIL_FROM!=='';toolsStart($cfg['title'],$cfg['description'],'https://ittai.ca/tools/'.$cfg['slug'].'/',$cfg['heading']);?>
<article data-tool="<?=h($cfg['slug'])?>"><header class="hero"><a href="/tools/">All calculators</a><h1><?=h($cfg['heading'])?></h1><p><?=h($cfg['description'])?></p></header>
<section class="rule-card"><div class="drawing"><?=$cfg['drawing']?></div><div><h2>Card</h2><dl><dt>Rule</dt><dd><?=h($cfg['rule'])?></dd><dt>Common mistake</dt><dd><?=h($cfg['mistake'])?></dd><dt>What varies</dt><dd><?=h($cfg['varies'])?></dd></dl></div></section>
<section class="calculator"><h2>Form</h2><form data-calculator novalidate><?=$cfg['form']?></form></section>
<section class="result" aria-live="polite"><h2>Result</h2><div data-result><p>Fill in the form to see your result.</p></div><div data-working></div></section>
<?php nextStep($emailOn)?></article><script type="module" src="/tools/_assets/tools.js"></script><?php toolsEnd();
