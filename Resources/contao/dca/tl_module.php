<?php

declare(strict_types=1);

use Contao\System;

System::loadLanguageFile('tl_module');

$GLOBALS['TL_DCA']['tl_module']['palettes']['ki_chat_widget'] = '{title_legend},name,headline,type;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';
