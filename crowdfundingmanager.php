<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2017 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

use Prism\Money\Money;
use Crowdfunding\Container\MoneyHelper;

// no direct access
defined('_JEXEC') or die;

/**
 * Crowdfunding Manager Plugin
 *
 * @package      Crowdfunding
 * @subpackage   Plugins
 */
class plgContentCrowdfundingManager extends JPlugin
{
    /**
     * Affects constructor behavior. If true, language files will be loaded automatically.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    protected $currentOption;
    protected $currentView;
    protected $currentTask;

    /**
     * Prepare a code that will be included after content.
     *
     * @param string    $context
     * @param stdClass  $project
     * @param Joomla\Registry\Registry    $params
     *
     * @throws \Exception
     * @return null|string
     */
    public function onContentAfterDisplay($context, $project, $params)
    {
        if ($this->isRestricted($context, $project)) {
            return null;
        }

        $app = JFactory::getApplication();
        /** @var $app JApplicationSite */

        // Get request data
        $this->currentOption = $app->input->getCmd('option');
        $this->currentView   = $app->input->getCmd('view');
        $this->currentTask   = $app->input->getCmd('task');

        // Include the script that display a dialog for action confirmation.
        $question = (!$project->published) ? JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_QUESTION_LAUNCH') : JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_QUESTION_STOP');
        $js = '
jQuery(document).ready(function() {
    jQuery("#js-cfmanager-launch").on("click", function(event) {
        event.preventDefault();

        if (window.confirm("'.$question.'")) {
            window.location = jQuery(this).attr("href");
        }
    });
});';
        $doc = JFactory::getDocument();
        $doc->addScriptDeclaration($js);

        // Generate content
        $content = '<div class="panel panel-default">';

        if ($this->params->get('display_title', 0)) {
            $content .= '<div class="panel-heading"><h4><span class="fa fa-cog" aria-hidden="true"></span> ' . JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_PROJECT_MANAGER') . '</h4></div>';
        }

        if ($this->params->get('display_toolbar', 0)) {
            $content .= $this->getToolbar($project);
        }

        $content .= '<div class="panel-body">';

        if ($this->params->get('display_statistics', 0)) {
            $container = Prism\Container::getContainer();
            $content  .= $this->getStatistics($project, $params, $container);
        }

        $content .= '</div>';
        $content .= '</div>';

        return $content;
    }

    /**
     * @param string $context
     * @param stdClass $project
     *
     * @return bool
     */
    private function isRestricted($context, $project)
    {
        $app = JFactory::getApplication();
        /** @var $app JApplicationSite */

        if ($app->isAdmin()) {
            return true;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return true;
        }

        if (strcmp('com_crowdfunding.details', $context) !== 0) {
            return true;
        }

        $userId = (int)JFactory::getUser()->get('id');
        if ($userId !== (int)$project->user_id) {
            return true;
        }

        return false;
    }

    private function getToolbar($project)
    {
        // Get current URL.
        $returnUrl = JUri::current();

        // Filter the URL.
        $filter    = JFilterInput::getInstance();
        $returnUrl = $filter->clean($returnUrl);

        $html   = array();
        $html[] = '<div class="cf-pm-toolbar">';

        if ($project->published and !$project->approved) {
            $html[] = '<p class="bg-info">' . JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_NOT_APPROVED_NOTIFICATION') . '</p>';
        }

        // Edit
        $html[] = '<a href="' . JRoute::_(CrowdfundingHelperRoute::getFormRoute($project->id)) . '" class="btn btn-default" role="button">';
        $html[] = '<span class="fa fa-pencil-square-o"></span>';
        $html[] = JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_EDIT');
        $html[] = '</a>';

        if (!$project->published) { // Display "Publish" button
            $html[] = '<a href="' . JRoute::_("index.php?option=com_crowdfunding&task=projects.savestate&id=" . $project->id . "&state=1&" . JSession::getFormToken() . "=1&return=".base64_encode($returnUrl)) . '" class="btn btn-default" role="button" id="js-cfmanager-launch">';
            $html[] = '<span class="fa fa-check-circle"></span>';
            $html[] = JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_LAUNCH');
            $html[] = '</a>';

        } else { // Display "Unpublish" button

            $html[] = '<a href="' . JRoute::_("index.php?option=com_crowdfunding&task=projects.savestate&id=" . $project->id . "&state=0&" . JSession::getFormToken() . "=1&return=".base64_encode($returnUrl)) . '" class="btn btn-danger" role="button" id="js-cfmanager-launch">';
            $html[] = '<span class="fa fa-times-circle"></span>';
            $html[] = JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_STOP');
            $html[] = '</a>';
        }

        // Manager
        $html[] = '<a href="' . JRoute::_(CrowdfundingHelperRoute::getFormRoute($project->id, 'manager')) . '" class="btn btn-default" role="button">';
        $html[] = '<span class="fa fa-wrench"></span>';
        $html[] = JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_MANAGER');
        $html[] = '</a>';

        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * @param stdClass $project
     * @param Joomla\Registry\Registry $params
     * @param Joomla\DI\Container $container
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     * @throws \OutOfBoundsException
     * @throws \Prism\Domain\BindException
     *
     * @return string
     */
    public function getStatistics($project, $params, $container)
    {
        $currency       = MoneyHelper::getCurrency($container, $params);
        $moneyFormatter = MoneyHelper::getMoneyFormatter($container, $params);

        $projectData    = CrowdfundingHelper::getProjectData($project->id);

        $html = array();

        $html[] = '<div class="panel panel-default">';

        $html[] = '<div class="panel-heading"><h5>' . JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_STATISTICS') . '</h5></div>';


        $html[] = '         <table class="table table-bordered">';

        // Hits
        $html[] = '             <tr>';
        $html[] = '                 <td>' . JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_HITS') . '</td>';
        $html[] = '                 <td>' . (int)$project->hits . '</td>';
        $html[] = '             </tr>';

        // Updates
        $html[] = '             <tr>';
        $html[] = '                 <td>' . JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_UPDATES') . '</td>';
        $html[] = '                 <td>' . Joomla\Utilities\ArrayHelper::getValue($projectData, 'updates', 0, 'integer') . '</td>';
        $html[] = '             </tr>';

        // Comments
        $html[] = '             <tr>';
        $html[] = '                 <td>' . JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_COMMENTS') . '</td>';
        $html[] = '                 <td>' . Joomla\Utilities\ArrayHelper::getValue($projectData, 'comments', 0, 'integer') . '</td>';
        $html[] = '             </tr>';

        // Funders
        $html[] = '             <tr>';
        $html[] = '                 <td>' . JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_FUNDERS') . '</td>';
        $html[] = '                 <td>' . Joomla\Utilities\ArrayHelper::getValue($projectData, 'funders', 0, 'integer') . '</td>';
        $html[] = '             </tr>';

        // Raised
        $html[] = '             <tr>';
        $html[] = '                 <td>' . JText::_('PLG_CONTENT_CROWDFUNDINGMANAGER_RAISED') . '</td>';
        $html[] = '                 <td>' . $moneyFormatter->formatCurrency(new Money($project->funded, $currency)) . '</td>';
        $html[] = '             </tr>';

        $html[] = '         </table>';

        $html[] = '</div>';

        return implode("\n", $html);
    }
}
