<?php

declare(strict_types=1);

namespace VenneMedia\ContaoKiAssistent\Controller\FrontendModule;

use Contao\Config;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\ModuleModel;
use Contao\StringUtil;
use Contao\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule(category: 'miscellaneous')]
class KiChatWidgetController extends AbstractFrontendModuleController
{
    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response
    {
        $apiKey = Config::get('kiAssistentApiKey');

        if (!$apiKey) {
            if ($this->container->get('contao.security.token_checker')->isPreviewMode()) {
                $template->error = 'Kein KI API Key konfiguriert. Bitte unter System > KI Assistent einrichten.';
            }
            return $template->getResponse();
        }

        $template->scriptUrl = 'https://portal.venne-software.de/contao-agent/api/ki/' . StringUtil::specialchars($apiKey) . '/widget.js';

        return $template->getResponse();
    }
}
