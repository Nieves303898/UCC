<?php /** Logo de la plataforma: el subido en Configuración o el de Paylab (isotipo + nombre). */
$plLogo = App\Settings::platformLogo();
$plName = App\Settings::platformName();
$plSize = $plSize ?? 30;
?><span class="pl-logo"><?php if ($plLogo): ?><img class="pl-custom" src="<?= e($plLogo) ?>" alt="<?= e($plName) ?>" style="height:<?= (int) $plSize ?>px"><?php else: ?><img src="assets/paylab-mark<?= !empty($plLight) ? '-light' : '' ?>.svg" width="<?= (int) $plSize ?>" height="<?= (int) $plSize ?>" alt=""><span class="pl-word" style="font-size:<?= round($plSize * .7) ?>px"><?= e(mb_strtolower($plName) === 'paylab' ? 'paylab' : $plName) ?></span><?php endif; ?></span>
