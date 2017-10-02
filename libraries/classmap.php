<?php
/**
 * @package    Webdata.Libraries
 *
 * @copyright  Copyright (C) 2005 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access.
defined('_JEXEC') or die;

JLoader::registerAlias('JRegistry',           '\\Webdata\\Registry\\Registry', '4.0');
JLoader::registerAlias('JRegistryFormat',     '\\Webdata\\Registry\\AbstractRegistryFormat', '4.0');
JLoader::registerAlias('JRegistryFormatIni',  '\\Webdata\\Registry\\Format\\Ini', '4.0');
JLoader::registerAlias('JRegistryFormatJson', '\\Webdata\\Registry\\Format\\Json', '4.0');
JLoader::registerAlias('JRegistryFormatPhp',  '\\Webdata\\Registry\\Format\\Php', '4.0');
JLoader::registerAlias('JRegistryFormatXml',  '\\Webdata\\Registry\\Format\\Xml', '4.0');
JLoader::registerAlias('JStringInflector',    '\\Webdata\\String\\Inflector', '4.0');
JLoader::registerAlias('JStringNormalise',    '\\Webdata\\String\\Normalise', '4.0');
JLoader::registerAlias('JApplicationWebClient', '\\Webdata\\Application\\Web\\WebClient', '4.0');
JLoader::registerAlias('JData',               '\\Webdata\\Data\\DataObject', '4.0');
JLoader::registerAlias('JDataSet',            '\\Webdata\\Data\\DataSet', '4.0');
JLoader::registerAlias('JDataDumpable',       '\\Webdata\\Data\\DumpableInterface', '4.0');
