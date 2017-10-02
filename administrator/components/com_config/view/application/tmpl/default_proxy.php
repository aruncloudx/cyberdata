<?php
/**
 * @package     Webdata.Administrator
 * @subpackage  com_config
 *
 * @copyright   Copyright (C) 2005 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

$this->name = JText::_('COM_CONFIG_PROXY_SETTINGS');
$this->fieldsname = 'proxy';
echo JLayoutHelper::render('joomla.content.options_default', $this);
