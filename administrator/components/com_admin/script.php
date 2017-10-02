<?php
/**
 * @package     Webdata.Administrator
 * @subpackage  com_admin
 *
 * @copyright   Copyright (C) 2005 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * Script file of Webdata CMS
 *
 * @since  1.6.4
 */
class WebdataInstallerScript
{
	/**
	 * The Webdata Version we are updating from
	 *
	 * @var    string
	 * @since  3.7
	 */
	protected $fromVersion = null;

	/**
	 * Function to act prior to installation process begins
	 *
	 * @param   string      $action     Which action is happening (install|uninstall|discover_install|update)
	 * @param   JInstaller  $installer  The class calling this method
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.7.0
	 */
	public function preflight($action, $installer)
	{
		if ($action === 'update')
		{
			// Get the version we are updating from
			if (!empty($installer->extension->manifest_cache))
			{
				$manifestValues = json_decode($installer->extension->manifest_cache, true);

				if ((array_key_exists('version', $manifestValues)))
				{
					$this->fromVersion = $manifestValues['version'];

					return true;
				}
			}

			return false;
		}

		return true;
	}

	/**
	 * Method to update Webdata!
	 *
	 * @param   JInstaller  $installer  The class calling this method
	 *
	 * @return  void
	 */
	public function update($installer)
	{
		$options['format']    = '{DATE}\t{TIME}\t{LEVEL}\t{CODE}\t{MESSAGE}';
		$options['text_file'] = 'webdata_update.php';

		JLog::addLogger($options, JLog::INFO, array('Update', 'databasequery', 'jerror'));

		try
		{
			JLog::add(JText::_('COM_JOOMLAUPDATE_UPDATE_LOG_DELETE_FILES'), JLog::INFO, 'Update');
		}
		catch (RuntimeException $exception)
		{
			// Informational log only
		}

		// This needs to stay for 2.5 update compatibility
		$this->deleteUnexistingFiles();
		$this->updateManifestCaches();
		$this->updateDatabase();
		$this->clearRadCache();
		$this->updateAssets($installer);
		$this->clearStatsCache();
		$this->convertTablesToUtf8mb4(true);
		$this->cleanWebdataCache();

		// VERY IMPORTANT! THIS METHOD SHOULD BE CALLED LAST, SINCE IT COULD
		// LOGOUT ALL THE USERS
		$this->flushSessions();
	}

	/**
	 * Called after any type of action
	 *
	 * @param   string      $action     Which action is happening (install|uninstall|discover_install|update)
	 * @param   JInstaller  $installer  The class calling this method
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.7.0
	 */
	public function postflight($action, $installer)
	{
		if ($action === 'update')
		{
			if (!empty($this->fromVersion) && version_compare($this->fromVersion, '3.7.0', 'lt'))
			{
				/*
				 * Do a check if the menu item exists, skip if it does. Only needed when we are in pre stable state.
				 */
				$db = JFactory::getDbo();

				$query = $db->getQuery(true)
					->select('id')
					->from($db->quoteName('#__menu'))
					->where($db->quoteName('menutype') . ' = ' . $db->quote('main'))
					->where($db->quoteName('title') . ' = ' . $db->quote('com_associations'))
					->where($db->quoteName('client_id') . ' = 1')
					->where($db->quoteName('component_id') . ' = 34');

				$result = $db->setQuery($query)->loadResult();

				if (!empty($result))
				{
					return true;
				}

				/*
				 * Add a menu item for com_associations, we need to do that here because with a plain sql statement we
				 * damage the nested set structure for the menu table
				 */
				$newMenuItem = JTable::getInstance('Menu');

				$data              = array();
				$data['menutype']  = 'main';
				$data['title']     = 'com_associations';
				$data['alias']     = 'Multilingual Associations';
				$data['path']      = 'Multilingual Associations';
				$data['link']      = 'index.php?option=com_associations';
				$data['type']      = 'component';
				$data['published'] = 1;
				$data['parent_id'] = 1;

				// We have used a SQL Statement to add the extension so using 34 is safe (fingers crossed)
				$data['component_id'] = 34;
				$data['img']          = 'class:associations';
				$data['language']     = '*';
				$data['client_id']    = 1;

				$newMenuItem->setLocation($data['parent_id'], 'last-child');

				if (!$newMenuItem->save($data))
				{
					// Install failed, roll back changes
					$installer->abort(JText::sprintf('JLIB_INSTALLER_ABORT_COMP_INSTALL_ROLLBACK', $newMenuItem->getError()));

					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Method to clear our stats plugin cache to ensure we get fresh data on Webdata Update
	 *
	 * @return  void
	 *
	 * @since   3.5
	 */
	protected function clearStatsCache()
	{
		$db = JFactory::getDbo();

		try
		{
			// Get the params for the stats plugin
			$params = $db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('params'))
					->from($db->quoteName('#__extensions'))
					->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
					->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
					->where($db->quoteName('element') . ' = ' . $db->quote('stats'))
			)->loadResult();
		}
		catch (Exception $e)
		{
			echo JText::sprintf('JLIB_DATABASE_ERROR_FUNCTION_FAILED', $e->getCode(), $e->getMessage()) . '<br />';

			return;
		}

		$params = json_decode($params, true);

		// Reset the last run parameter
		if (isset($params['lastrun']))
		{
			$params['lastrun'] = '';
		}

		$params = json_encode($params);

		$query = $db->getQuery(true)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('params') . ' = ' . $db->quote($params))
			->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
			->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
			->where($db->quoteName('element') . ' = ' . $db->quote('stats'));

		try
		{
			$db->setQuery($query)->execute();
		}
		catch (Exception $e)
		{
			echo JText::sprintf('JLIB_DATABASE_ERROR_FUNCTION_FAILED', $e->getCode(), $e->getMessage()) . '<br />';

			return;
		}
	}

	/**
	 * Method to update Database
	 *
	 * @return  void
	 */
	protected function updateDatabase()
	{
		if (JFactory::getDbo()->getServerType() === 'mysql')
		{
			$this->updateDatabaseMysql();
		}

		$this->uninstallEosPlugin();
		$this->removeJedUpdateserver();
	}

	/**
	 * Method to update MySQL Database
	 *
	 * @return  void
	 */
	protected function updateDatabaseMysql()
	{
		$db = JFactory::getDbo();

		$db->setQuery('SHOW ENGINES');

		try
		{
			$results = $db->loadObjectList();
		}
		catch (Exception $e)
		{
			echo JText::sprintf('JLIB_DATABASE_ERROR_FUNCTION_FAILED', $e->getCode(), $e->getMessage()) . '<br />';

			return;
		}

		foreach ($results as $result)
		{
			if ($result->Support != 'DEFAULT')
			{
				continue;
			}

			$db->setQuery('ALTER TABLE #__update_sites_extensions ENGINE = ' . $result->Engine);

			try
			{
				$db->execute();
			}
			catch (Exception $e)
			{
				echo JText::sprintf('JLIB_DATABASE_ERROR_FUNCTION_FAILED', $e->getCode(), $e->getMessage()) . '<br />';

				return;
			}

			break;
		}
	}

	/**
	 * Uninstall the 2.5 EOS plugin
	 *
	 * @return  void
	 */
	protected function uninstallEosPlugin()
	{
		$db = JFactory::getDbo();

		// Check if the 2.5 EOS plugin is present and uninstall it if so
		$id = $db->setQuery(
			$db->getQuery(true)
				->select('extension_id')
				->from('#__extensions')
				->where('name = ' . $db->quote('PLG_EOSNOTIFY'))
		)->loadResult();

		// Skip update when id doesn’t exists
		if (!$id)
		{
			return;
		}

		// We need to unprotect the plugin so we can uninstall it
		$db->setQuery(
			$db->getQuery(true)
				->update('#__extensions')
				->set('protected = 0')
				->where($db->quoteName('extension_id') . ' = ' . $id)
		)->execute();

		$installer = new JInstaller;
		$installer->uninstall('plugin', $id);
	}

	/**
	 * Remove the never used JED Updateserver
	 *
	 * @return  void
	 *
	 * @since   3.7.0
	 */
	protected function removeJedUpdateserver()
	{
		$db = JFactory::getDbo();

		try
		{
			// Get the update site ID of the JED Update server
			$id = $db->setQuery(
				$db->getQuery(true)
					->select('update_site_id')
					->from($db->quoteName('#__update_sites'))
					->where($db->quoteName('location') . ' = ' . $db->quote('https://update.webdata.org/jed/list.xml'))
			)->loadResult();

			// Skip delete when id doesn’t exists
			if (!$id)
			{
				return;
			}

			// Delete from update sites
			$db->setQuery(
				$db->getQuery(true)
					->delete($db->quoteName('#__update_sites'))
					->where($db->quoteName('update_site_id') . ' = ' . $id)
			)->execute();

			// Delete from update sites extensions
			$db->setQuery(
				$db->getQuery(true)
					->delete($db->quoteName('#__update_sites_extensions'))
					->where($db->quoteName('update_site_id') . ' = ' . $id)
			)->execute();
		}
		catch (Exception $e)
		{
			echo JText::sprintf('JLIB_DATABASE_ERROR_FUNCTION_FAILED', $e->getCode(), $e->getMessage()) . '<br />';

			return;
		}
	}

	/**
	 * Update the manifest caches
	 *
	 * @return  void
	 */
	protected function updateManifestCaches()
	{
		$extensions = JExtensionHelper::getCoreExtensions();

		// Attempt to refresh manifest caches
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select('*')
			->from('#__extensions');

		foreach ($extensions as $extension)
		{
			$query->where(
				'type=' . $db->quote($extension[0])
				. ' AND element=' . $db->quote($extension[1])
				. ' AND folder=' . $db->quote($extension[2])
				. ' AND client_id=' . $extension[3], 'OR'
			);
		}

		$db->setQuery($query);

		try
		{
			$extensions = $db->loadObjectList();
		}
		catch (Exception $e)
		{
			echo JText::sprintf('JLIB_DATABASE_ERROR_FUNCTION_FAILED', $e->getCode(), $e->getMessage()) . '<br />';

			return;
		}

		$installer = new JInstaller;

		foreach ($extensions as $extension)
		{
			if (!$installer->refreshManifestCache($extension->extension_id))
			{
				echo JText::sprintf('FILES_JOOMLA_ERROR_MANIFEST', $extension->type, $extension->element, $extension->name, $extension->client_id) . '<br />';
			}
		}
	}

	/**
	 * Delete files that should not exist
	 *
	 * @return  void
	 */
	public function deleteUnexistingFiles()
	{
		$files = array(
			// Webdata 1.6 - 1.7 - 2.5
			'/libraries/cms/cmsloader.php',
			'/libraries/webdata/database/databaseexception.php',
			'/libraries/webdata/database/databasequery.php',
			'/libraries/webdata/environment/response.php',
			'/libraries/webdata/form/fields/templatestyle.php',
			'/libraries/webdata/form/fields/user.php',
			'/libraries/webdata/form/fields/menu.php',
			'/libraries/webdata/form/fields/helpsite.php',
			'/libraries/webdata/github/gists.php',
			'/libraries/webdata/github/issues.php',
			'/libraries/webdata/github/pulls.php',
			'/libraries/webdata/log/logentry.php',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.0.sql',
			'/administrator/components/com_admin/sql/updates/sqlsrv/2.5.2-2012-03-05.sql',
			'/administrator/components/com_admin/sql/updates/sqlsrv/2.5.3-2012-03-13.sql',
			'/administrator/components/com_admin/sql/updates/sqlsrv/index.html',
			'/administrator/components/com_content/models/fields/filters.php',
			'/administrator/components/com_users/controllers/config.php',
			'/administrator/components/com_users/helpers/levels.php',
			'/administrator/language/en-GB/en-GB.plg_system_finder.ini',
			'/administrator/language/en-GB/en-GB.plg_system_finder.sys.ini',
			'/administrator/modules/mod_quickicon/tmpl/default_button.php',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advhr/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advimage/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlink/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlist/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/autolink/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/autoresize/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/autosave/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/bbcode/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/contextmenu/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/directionality/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullpage/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullscreen/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/iespell/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/insertdatetime/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/layer/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/lists/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/media/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/nonbreaking/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/noneditable/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/pagebreak/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/paste/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/preview/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/print/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/save/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/searchreplace/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/spellchecker/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/style/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/tabfocus/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/template/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/visualchars/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/wordcount/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/editor_plugin_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/editor_template_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/editor_template_src.js',
			'/media/editors/tinymce/jscripts/tiny_mce/tiny_mce_src.js',
			'/media/com_finder/images/calendar.png',
			'/media/com_finder/images/mime/index.html',
			'/media/com_finder/images/mime/pdf.png',
			'/components/com_media/controller.php',
			'/components/com_media/helpers/index.html',
			'/components/com_media/helpers/media.php',
			'/components/com_fields/controllers/field.php',
			// Webdata 3.0
			'/administrator/components/com_admin/sql/updates/mysql/1.7.0-2011-06-06-2.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.0-2011-06-06.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.0.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.1-2011-09-15-2.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.1-2011-09-15-3.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.1-2011-09-15-4.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.1-2011-09-15.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.1-2011-09-17.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.1-2011-09-20.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.3-2011-10-15.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.3-2011-10-19.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.3-2011-11-10.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.4-2011-11-19.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.4-2011-11-23.sql',
			'/administrator/components/com_admin/sql/updates/mysql/1.7.4-2011-12-12.sql',
			'/administrator/components/com_admin/views/sysinfo/tmpl/default_navigation.php',
			'/administrator/components/com_categories/config.xml',
			'/administrator/components/com_categories/helpers/categoriesadministrator.php',
			'/administrator/components/com_contact/elements/contact.php',
			'/administrator/components/com_contact/elements/index.html',
			'/administrator/components/com_content/elements/article.php',
			'/administrator/components/com_content/elements/author.php',
			'/administrator/components/com_content/elements/index.html',
			'/administrator/components/com_installer/models/fields/client.php',
			'/administrator/components/com_installer/models/fields/group.php',
			'/administrator/components/com_installer/models/fields/index.html',
			'/administrator/components/com_installer/models/fields/search.php',
			'/administrator/components/com_installer/models/forms/index.html',
			'/administrator/components/com_installer/models/forms/manage.xml',
			'/administrator/components/com_installer/views/install/tmpl/default_form.php',
			'/administrator/components/com_installer/views/manage/tmpl/default_filter.php',
			'/administrator/components/com_languages/views/installed/tmpl/default_ftp.php',
			'/administrator/components/com_languages/views/installed/tmpl/default_navigation.php',
			'/administrator/components/com_modules/models/fields/index.html',
			'/administrator/components/com_modules/models/fields/moduleorder.php',
			'/administrator/components/com_modules/models/fields/moduleposition.php',
			'/administrator/components/com_newsfeeds/elements/index.html',
			'/administrator/components/com_newsfeeds/elements/newsfeed.php',
			'/administrator/components/com_templates/views/prevuuw/index.html',
			'/administrator/components/com_templates/views/prevuuw/tmpl/default.php',
			'/administrator/components/com_templates/views/prevuuw/tmpl/index.html',
			'/administrator/components/com_templates/views/prevuuw/view.html.php',
			'/administrator/includes/menu.php',
			'/administrator/includes/router.php',
			'/administrator/manifests/packages/pkg_webdata.xml',
			'/administrator/modules/mod_submenu/helper.php',
			'/administrator/templates/hathor/css/ie6.css',
			'/administrator/templates/hathor/html/mod_submenu/index.html',
			'/administrator/templates/hathor/html/mod_submenu/default.php',
			'/components/com_media/controller.php',
			'/components/com_media/helpers/index.html',
			'/components/com_media/helpers/media.php',
			'/includes/menu.php',
			'/includes/pathway.php',
			'/includes/router.php',
			'/language/en-GB/en-GB.pkg_webdata.sys.ini',
			'/libraries/cms/controller/index.html',
			'/libraries/cms/controller/legacy.php',
			'/libraries/cms/model/index.html',
			'/libraries/cms/model/legacy.php',
			'/libraries/cms/schema/changeitemmysql.php',
			'/libraries/cms/schema/changeitemsqlazure.php',
			'/libraries/cms/schema/changeitemsqlsrv.php',
			'/libraries/cms/view/index.html',
			'/libraries/cms/view/legacy.php',
			'/libraries/webdata/application/application.php',
			'/libraries/webdata/application/categories.php',
			'/libraries/webdata/application/cli/daemon.php',
			'/libraries/webdata/application/cli/index.html',
			'/libraries/webdata/application/component/controller.php',
			'/libraries/webdata/application/component/controlleradmin.php',
			'/libraries/webdata/application/component/controllerform.php',
			'/libraries/webdata/application/component/helper.php',
			'/libraries/webdata/application/component/index.html',
			'/libraries/webdata/application/component/model.php',
			'/libraries/webdata/application/component/modeladmin.php',
			'/libraries/webdata/application/component/modelform.php',
			'/libraries/webdata/application/component/modelitem.php',
			'/libraries/webdata/application/component/modellist.php',
			'/libraries/webdata/application/component/view.php',
			'/libraries/webdata/application/helper.php',
			'/libraries/webdata/application/input.php',
			'/libraries/webdata/application/input/cli.php',
			'/libraries/webdata/application/input/cookie.php',
			'/libraries/webdata/application/input/files.php',
			'/libraries/webdata/application/input/index.html',
			'/libraries/webdata/application/menu.php',
			'/libraries/webdata/application/module/helper.php',
			'/libraries/webdata/application/module/index.html',
			'/libraries/webdata/application/pathway.php',
			'/libraries/webdata/application/web/webclient.php',
			'/libraries/webdata/base/node.php',
			'/libraries/webdata/base/object.php',
			'/libraries/webdata/base/observable.php',
			'/libraries/webdata/base/observer.php',
			'/libraries/webdata/base/tree.php',
			'/libraries/webdata/cache/storage/eaccelerator.php',
			'/libraries/webdata/cache/storage/helpers/helper.php',
			'/libraries/webdata/cache/storage/helpers/index.html',
			'/libraries/webdata/database/database/index.html',
			'/libraries/webdata/database/database/mysql.php',
			'/libraries/webdata/database/database/mysqlexporter.php',
			'/libraries/webdata/database/database/mysqli.php',
			'/libraries/webdata/database/database/mysqliexporter.php',
			'/libraries/webdata/database/database/mysqliimporter.php',
			'/libraries/webdata/database/database/mysqlimporter.php',
			'/libraries/webdata/database/database/mysqliquery.php',
			'/libraries/webdata/database/database/mysqlquery.php',
			'/libraries/webdata/database/database/sqlazure.php',
			'/libraries/webdata/database/database/sqlazurequery.php',
			'/libraries/webdata/database/database/sqlsrv.php',
			'/libraries/webdata/database/database/sqlsrvquery.php',
			'/libraries/webdata/database/exception.php',
			'/libraries/webdata/database/table.php',
			'/libraries/webdata/database/table/asset.php',
			'/libraries/webdata/database/table/category.php',
			'/libraries/webdata/database/table/content.php',
			'/libraries/webdata/database/table/extension.php',
			'/libraries/webdata/database/table/index.html',
			'/libraries/webdata/database/table/language.php',
			'/libraries/webdata/database/table/menu.php',
			'/libraries/webdata/database/table/menutype.php',
			'/libraries/webdata/database/table/module.php',
			'/libraries/webdata/database/table/session.php',
			'/libraries/webdata/database/table/update.php',
			'/libraries/webdata/database/table/user.php',
			'/libraries/webdata/database/table/usergroup.php',
			'/libraries/webdata/database/table/viewlevel.php',
			'/libraries/webdata/database/tablenested.php',
			'/libraries/webdata/environment/request.php',
			'/libraries/webdata/environment/uri.php',
			'/libraries/webdata/error/error.php',
			'/libraries/webdata/error/exception.php',
			'/libraries/webdata/error/index.html',
			'/libraries/webdata/error/log.php',
			'/libraries/webdata/error/profiler.php',
			'/libraries/webdata/filesystem/archive.php',
			'/libraries/webdata/filesystem/archive/bzip2.php',
			'/libraries/webdata/filesystem/archive/gzip.php',
			'/libraries/webdata/filesystem/archive/index.html',
			'/libraries/webdata/filesystem/archive/tar.php',
			'/libraries/webdata/filesystem/archive/zip.php',
			'/libraries/webdata/form/fields/category.php',
			'/libraries/webdata/form/fields/componentlayout.php',
			'/libraries/webdata/form/fields/contentlanguage.php',
			'/libraries/webdata/form/fields/editor.php',
			'/libraries/webdata/form/fields/editors.php',
			'/libraries/webdata/form/fields/media.php',
			'/libraries/webdata/form/fields/menuitem.php',
			'/libraries/webdata/form/fields/modulelayout.php',
			'/libraries/webdata/html/editor.php',
			'/libraries/webdata/html/html/access.php',
			'/libraries/webdata/html/html/batch.php',
			'/libraries/webdata/html/html/behavior.php',
			'/libraries/webdata/html/html/category.php',
			'/libraries/webdata/html/html/content.php',
			'/libraries/webdata/html/html/contentlanguage.php',
			'/libraries/webdata/html/html/date.php',
			'/libraries/webdata/html/html/email.php',
			'/libraries/webdata/html/html/form.php',
			'/libraries/webdata/html/html/grid.php',
			'/libraries/webdata/html/html/image.php',
			'/libraries/webdata/html/html/index.html',
			'/libraries/webdata/html/html/jgrid.php',
			'/libraries/webdata/html/html/list.php',
			'/libraries/webdata/html/html/menu.php',
			'/libraries/webdata/html/html/number.php',
			'/libraries/webdata/html/html/rules.php',
			'/libraries/webdata/html/html/select.php',
			'/libraries/webdata/html/html/sliders.php',
			'/libraries/webdata/html/html/string.php',
			'/libraries/webdata/html/html/tabs.php',
			'/libraries/webdata/html/html/tel.php',
			'/libraries/webdata/html/html/user.php',
			'/libraries/webdata/html/pagination.php',
			'/libraries/webdata/html/pane.php',
			'/libraries/webdata/html/parameter.php',
			'/libraries/webdata/html/parameter/element.php',
			'/libraries/webdata/html/parameter/element/calendar.php',
			'/libraries/webdata/html/parameter/element/category.php',
			'/libraries/webdata/html/parameter/element/componentlayouts.php',
			'/libraries/webdata/html/parameter/element/contentlanguages.php',
			'/libraries/webdata/html/parameter/element/editors.php',
			'/libraries/webdata/html/parameter/element/filelist.php',
			'/libraries/webdata/html/parameter/element/folderlist.php',
			'/libraries/webdata/html/parameter/element/helpsites.php',
			'/libraries/webdata/html/parameter/element/hidden.php',
			'/libraries/webdata/html/parameter/element/imagelist.php',
			'/libraries/webdata/html/parameter/element/index.html',
			'/libraries/webdata/html/parameter/element/languages.php',
			'/libraries/webdata/html/parameter/element/list.php',
			'/libraries/webdata/html/parameter/element/menu.php',
			'/libraries/webdata/html/parameter/element/menuitem.php',
			'/libraries/webdata/html/parameter/element/modulelayouts.php',
			'/libraries/webdata/html/parameter/element/password.php',
			'/libraries/webdata/html/parameter/element/radio.php',
			'/libraries/webdata/html/parameter/element/spacer.php',
			'/libraries/webdata/html/parameter/element/sql.php',
			'/libraries/webdata/html/parameter/element/templatestyle.php',
			'/libraries/webdata/html/parameter/element/text.php',
			'/libraries/webdata/html/parameter/element/textarea.php',
			'/libraries/webdata/html/parameter/element/timezones.php',
			'/libraries/webdata/html/parameter/element/usergroup.php',
			'/libraries/webdata/html/parameter/index.html',
			'/libraries/webdata/html/toolbar.php',
			'/libraries/webdata/html/toolbar/button.php',
			'/libraries/webdata/html/toolbar/button/confirm.php',
			'/libraries/webdata/html/toolbar/button/custom.php',
			'/libraries/webdata/html/toolbar/button/help.php',
			'/libraries/webdata/html/toolbar/button/index.html',
			'/libraries/webdata/html/toolbar/button/link.php',
			'/libraries/webdata/html/toolbar/button/popup.php',
			'/libraries/webdata/html/toolbar/button/separator.php',
			'/libraries/webdata/html/toolbar/button/standard.php',
			'/libraries/webdata/html/toolbar/index.html',
			'/libraries/webdata/image/filters/brightness.php',
			'/libraries/webdata/image/filters/contrast.php',
			'/libraries/webdata/image/filters/edgedetect.php',
			'/libraries/webdata/image/filters/emboss.php',
			'/libraries/webdata/image/filters/grayscale.php',
			'/libraries/webdata/image/filters/index.html',
			'/libraries/webdata/image/filters/negate.php',
			'/libraries/webdata/image/filters/sketchy.php',
			'/libraries/webdata/image/filters/smooth.php',
			'/libraries/webdata/language/help.php',
			'/libraries/webdata/language/latin_transliterate.php',
			'/libraries/webdata/log/logexception.php',
			'/libraries/webdata/log/loggers/database.php',
			'/libraries/webdata/log/loggers/echo.php',
			'/libraries/webdata/log/loggers/formattedtext.php',
			'/libraries/webdata/log/loggers/index.html',
			'/libraries/webdata/log/loggers/messagequeue.php',
			'/libraries/webdata/log/loggers/syslog.php',
			'/libraries/webdata/log/loggers/w3c.php',
			'/libraries/webdata/methods.php',
			'/libraries/webdata/session/storage/eaccelerator.php',
			'/libraries/webdata/string/stringnormalize.php',
			'/libraries/webdata/utilities/date.php',
			'/libraries/webdata/utilities/simplecrypt.php',
			'/libraries/webdata/utilities/simplexml.php',
			'/libraries/webdata/utilities/string.php',
			'/libraries/webdata/utilities/xmlelement.php',
			'/media/plg_quickicon_extensionupdate/extensionupdatecheck.js',
			'/media/plg_quickicon_webdataupdate/jupdatecheck.js',
			// Webdata! 3.1
			'/libraries/webdata/application/router.php',
			'/libraries/webdata/form/rules/boolean.php',
			'/libraries/webdata/form/rules/color.php',
			'/libraries/webdata/form/rules/email.php',
			'/libraries/webdata/form/rules/equals.php',
			'/libraries/webdata/form/rules/index.html',
			'/libraries/webdata/form/rules/options.php',
			'/libraries/webdata/form/rules/rules.php',
			'/libraries/webdata/form/rules/tel.php',
			'/libraries/webdata/form/rules/url.php',
			'/libraries/webdata/form/rules/username.php',
			'/libraries/webdata/html/access.php',
			'/libraries/webdata/html/behavior.php',
			'/libraries/webdata/html/content.php',
			'/libraries/webdata/html/date.php',
			'/libraries/webdata/html/email.php',
			'/libraries/webdata/html/form.php',
			'/libraries/webdata/html/grid.php',
			'/libraries/webdata/html/html.php',
			'/libraries/webdata/html/index.html',
			'/libraries/webdata/html/jgrid.php',
			'/libraries/webdata/html/list.php',
			'/libraries/webdata/html/number.php',
			'/libraries/webdata/html/rules.php',
			'/libraries/webdata/html/select.php',
			'/libraries/webdata/html/sliders.php',
			'/libraries/webdata/html/string.php',
			'/libraries/webdata/html/tabs.php',
			'/libraries/webdata/html/tel.php',
			'/libraries/webdata/html/user.php',
			'/libraries/webdata/html/language/index.html',
			'/libraries/webdata/html/language/en-GB/en-GB.jhtmldate.ini',
			'/libraries/webdata/html/language/en-GB/index.html',
			'/libraries/webdata/installer/adapters/component.php',
			'/libraries/webdata/installer/adapters/file.php',
			'/libraries/webdata/installer/adapters/index.html',
			'/libraries/webdata/installer/adapters/language.php',
			'/libraries/webdata/installer/adapters/library.php',
			'/libraries/webdata/installer/adapters/module.php',
			'/libraries/webdata/installer/adapters/package.php',
			'/libraries/webdata/installer/adapters/plugin.php',
			'/libraries/webdata/installer/adapters/template.php',
			'/libraries/webdata/installer/extension.php',
			'/libraries/webdata/installer/helper.php',
			'/libraries/webdata/installer/index.html',
			'/libraries/webdata/installer/librarymanifest.php',
			'/libraries/webdata/installer/packagemanifest.php',
			'/libraries/webdata/pagination/index.html',
			'/libraries/webdata/pagination/object.php',
			'/libraries/webdata/pagination/pagination.php',
			'/libraries/legacy/html/contentlanguage.php',
			'/libraries/legacy/html/index.html',
			'/libraries/legacy/html/menu.php',
			'/libraries/legacy/menu/index.html',
			'/libraries/legacy/menu/menu.php',
			'/libraries/legacy/pathway/index.html',
			'/libraries/legacy/pathway/pathway.php',
			'/media/system/css/mooRainbow.css',
			'/media/system/js/mooRainbow-uncompressed.js',
			'/media/system/js/mooRainbow.js',
			'/media/system/js/swf-uncompressed.js',
			'/media/system/js/swf.js',
			'/media/system/js/uploader-uncompressed.js',
			'/media/system/js/uploader.js',
			'/media/system/swf/index.html',
			'/media/system/swf/uploader.swf',
			// Webdata! 3.2
			'/administrator/components/com_contact/models/fields/modal/contacts.php',
			'/administrator/components/com_newsfeeds/models/fields/modal/newsfeeds.php',
			'/libraries/idna_convert/example.php',
			'/media/editors/tinymce/jscripts/tiny_mce/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/license.txt',
			'/media/editors/tinymce/jscripts/tiny_mce/tiny_mce.js',
			'/media/editors/tinymce/jscripts/tiny_mce/tiny_mce_popup.js',
			'/media/editors/tinymce/jscripts/tiny_mce/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/langs/en.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advhr/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advhr/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advhr/rule.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advhr/css/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advhr/css/advhr.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advhr/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advhr/js/rule.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advhr/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advhr/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advimage/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advimage/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advimage/image.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advimage/css/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advimage/css/advimage.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advimage/img/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advimage/img/sample.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advimage/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advimage/js/image.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advimage/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advimage/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlink/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlink/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlink/link.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlink/css/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlink/css/advlink.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlink/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlink/js/advlink.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlink/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlink/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlist/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/advlist/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/autolink/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/autolink/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/autoresize/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/autoresize/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/autosave/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/autosave/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/autosave/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/autosave/langs/en.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/bbcode/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/bbcode/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/compat3x/editable_selects.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/compat3x/form_utils.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/compat3x/mctabs.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/compat3x/tiny_mce_popup.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/compat3x/validate.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/contextmenu/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/contextmenu/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/directionality/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/directionality/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/emotions.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-cool.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-cry.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-embarassed.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-foot-in-mouth.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-frown.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-innocent.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-kiss.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-laughing.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-money-mouth.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-sealed.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-smile.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-surprised.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-tongue-out.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-undecided.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-wink.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/img/smiley-yell.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/js/emotions.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/emotions/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullpage/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullpage/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullpage/fullpage.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullpage/css/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullpage/css/fullpage.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullpage/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullpage/js/fullpage.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullpage/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullpage/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullscreen/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullscreen/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/fullscreen/fullscreen.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/iespell/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/iespell/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/template.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/skins/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/skins/clearlooks2/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/skins/clearlooks2/window.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/skins/clearlooks2/img/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/skins/clearlooks2/img/alert.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/skins/clearlooks2/img/button.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/skins/clearlooks2/img/buttons.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/skins/clearlooks2/img/confirm.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/skins/clearlooks2/img/corners.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/skins/clearlooks2/img/horizontal.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/inlinepopups/skins/clearlooks2/img/vertical.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/insertdatetime/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/insertdatetime/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/layer/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/layer/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/lists/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/lists/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/media/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/media/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/media/media.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/media/moxieplayer.swf',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/media/css/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/media/css/media.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/media/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/media/js/embed.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/media/js/media.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/media/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/media/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/nonbreaking/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/nonbreaking/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/noneditable/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/noneditable/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/pagebreak/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/pagebreak/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/paste/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/paste/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/paste/pastetext.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/paste/pastetext.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/paste/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/paste/js/pastetext.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/paste/js/pasteword.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/paste/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/paste/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/preview/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/preview/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/preview/example.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/preview/preview.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/preview/jscripts/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/preview/jscripts/embed.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/print/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/print/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/save/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/save/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/searchreplace/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/searchreplace/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/searchreplace/searchreplace.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/searchreplace/css/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/searchreplace/css/searchreplace.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/searchreplace/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/searchreplace/js/searchreplace.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/searchreplace/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/searchreplace/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/spellchecker/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/spellchecker/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/spellchecker/css/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/spellchecker/css/content.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/spellchecker/img/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/spellchecker/img/wline.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/style/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/style/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/style/props.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/style/readme.txt',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/style/css/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/style/css/props.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/style/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/style/js/props.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/style/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/style/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/tabfocus/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/tabfocus/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/cell.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/merge_cells.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/row.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/table.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/css/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/css/cell.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/css/row.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/css/table.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/js/cell.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/js/merge_cells.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/js/row.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/js/table.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/table/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/template/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/template/blank.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/template/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/template/template.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/template/css/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/template/css/template.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/template/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/template/js/template.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/template/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/template/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/visualblocks/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/visualblocks/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/visualblocks/css/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/visualblocks/css/visualblocks.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/visualchars/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/visualchars/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/wordcount/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/wordcount/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/abbr.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/acronym.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/attributes.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/cite.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/del.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/editor_plugin.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/ins.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/css/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/css/attributes.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/css/popup.css',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/js/abbr.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/js/acronym.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/js/attributes.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/js/cite.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/js/del.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/js/element_common.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/js/ins.js',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/plugins/xhtmlxtras/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/about.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/anchor.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/charmap.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/color_picker.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/editor_template.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/image.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/link.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/shortcuts.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/source_editor.htm',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/img/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/img/colorpicker.jpg',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/img/flash.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/img/icons.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/img/iframe.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/img/pagebreak.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/img/quicktime.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/img/realmedia.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/img/shockwave.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/img/trans.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/img/video.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/img/windowsmedia.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/js/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/js/about.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/js/anchor.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/js/charmap.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/js/color_picker.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/js/image.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/js/link.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/js/source_editor.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/langs/en.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/langs/en_dlg.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/default/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/default/content.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/default/dialog.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/default/ui.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/default/img/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/default/img/buttons.png',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/default/img/items.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/default/img/menu_arrow.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/default/img/menu_check.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/default/img/progress.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/default/img/tabs.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/highcontrast/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/highcontrast/content.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/highcontrast/dialog.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/highcontrast/ui.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/o2k7/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/o2k7/content.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/o2k7/dialog.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/o2k7/ui.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/o2k7/ui_black.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/o2k7/ui_silver.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/o2k7/img/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/o2k7/img/button_bg.png',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/o2k7/img/button_bg_black.png',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/advanced/skins/o2k7/img/button_bg_silver.png',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/editor_template.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/img/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/img/icons.gif',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/langs/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/langs/en.js',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/skins/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/skins/default/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/skins/default/content.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/skins/default/ui.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/skins/o2k7/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/skins/o2k7/content.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/skins/o2k7/ui.css',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/skins/o2k7/img/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/themes/simple/skins/o2k7/img/button_bg.png',
			'/media/editors/tinymce/jscripts/tiny_mce/utils/index.html',
			'/media/editors/tinymce/jscripts/tiny_mce/utils/editable_selects.js',
			'/media/editors/tinymce/jscripts/tiny_mce/utils/form_utils.js',
			'/media/editors/tinymce/jscripts/tiny_mce/utils/mctabs.js',
			'/media/editors/tinymce/jscripts/tiny_mce/utils/validate.js',
			'/administrator/components/com_banners/models/fields/ordering.php',
			'/administrator/components/com_contact/models/fields/ordering.php',
			'/administrator/components/com_newsfeeds/models/fields/ordering.php',
			'/administrator/components/com_plugins/models/fields/ordering.php',
			'/administrator/components/com_weblinks/models/fields/ordering.php',
			'/administrator/includes/application.php',
			'/includes/application.php',
			'/libraries/legacy/application/helper.php',
			'/libraries/webdata/plugin/helper.php',
			'/libraries/webdata/plugin/index.html',
			'/libraries/webdata/plugin/plugin.php',
			'/libraries/legacy/component/helper.php',
			'/libraries/legacy/component/index.html',
			'/libraries/legacy/module/helper.php',
			'/libraries/legacy/module/index.html',
			'/administrator/components/com_templates/controllers/source.php',
			'/administrator/components/com_templates/models/source.php',
			'/administrator/components/com_templates/views/source/index.html',
			'/administrator/components/com_templates/views/source/tmpl/edit.php',
			'/administrator/components/com_templates/views/source/tmpl/edit_ftp.php',
			'/administrator/components/com_templates/views/source/tmpl/index.html',
			'/administrator/components/com_templates/views/source/view.html.php',
			'/media/editors/codemirror/css/csscolors.css',
			'/media/editors/codemirror/css/jscolors.css',
			'/media/editors/codemirror/css/phpcolors.css',
			'/media/editors/codemirror/css/sparqlcolors.css',
			'/media/editors/codemirror/css/xmlcolors.css',
			'/media/editors/codemirror/js/basefiles-uncompressed.js',
			'/media/editors/codemirror/js/basefiles.js',
			'/media/editors/codemirror/js/codemirror-uncompressed.js',
			'/media/editors/codemirror/js/editor.js',
			'/media/editors/codemirror/js/highlight.js',
			'/media/editors/codemirror/js/mirrorframe.js',
			'/media/editors/codemirror/js/parsecss.js',
			'/media/editors/codemirror/js/parsedummy.js',
			'/media/editors/codemirror/js/parsehtmlmixed.js',
			'/media/editors/codemirror/js/parsejavascript.js',
			'/media/editors/codemirror/js/parsephp.js',
			'/media/editors/codemirror/js/parsephphtmlmixed.js',
			'/media/editors/codemirror/js/parsesparql.js',
			'/media/editors/codemirror/js/parsexml.js',
			'/media/editors/codemirror/js/select.js',
			'/media/editors/codemirror/js/stringstream.js',
			'/media/editors/codemirror/js/tokenize.js',
			'/media/editors/codemirror/js/tokenizejavascript.js',
			'/media/editors/codemirror/js/tokenizephp.js',
			'/media/editors/codemirror/js/undo.js',
			'/media/editors/codemirror/js/util.js',
			'/administrator/components/com_weblinks/models/fields/index.html',
			'/plugins/user/webdata/postinstall/actions.php',
			'/plugins/user/webdata/postinstall/index.html',
			'/media/com_finder/js/finder.js',
			'/media/com_finder/js/highlighter.js',
			'/libraries/webdata/registry/format.php',
			'/libraries/webdata/registry/index.html',
			'/libraries/webdata/registry/registry.php',
			'/libraries/webdata/registry/format/index.html',
			'/libraries/webdata/registry/format/ini.php',
			'/libraries/webdata/registry/format/json.php',
			'/libraries/webdata/registry/format/php.php',
			'/libraries/webdata/registry/format/xml.php',
			'/libraries/webdata/github/users.php',
			'/media/system/js/validate-jquery-uncompressed.js',
			'/templates/beez3/html/message.php',
			'/libraries/fof/platform/webdata.php',
			'/libraries/fof/readme.txt',
			// Webdata 3.3.1
			'/administrator/templates/isis/html/message.php',
			// Webdata 3.3.6
			'/media/editors/tinymce/plugins/compat3x/editable_selects.js',
			'/media/editors/tinymce/plugins/compat3x/form_utils.js',
			'/media/editors/tinymce/plugins/compat3x/mctabs.js',
			'/media/editors/tinymce/plugins/compat3x/tiny_mce_popup.js',
			'/media/editors/tinymce/plugins/compat3x/validate.js',
			// Webdata! 3.4
			'/administrator/components/com_tags/helpers/html/index.html',
			'/administrator/components/com_tags/models/fields/index.html',
			'/administrator/manifests/libraries/phpmailer.xml',
			'/administrator/templates/hathor/html/com_finder/filter/index.html',
			'/administrator/templates/hathor/html/com_finder/statistics/index.html',
			'/components/com_contact/helpers/icon.php',
			'/language/en-GB/en-GB.lib_phpmailer.sys.ini',
			'/libraries/compat/jsonserializable.php',
			'/libraries/compat/password/lib/index.html',
			'/libraries/compat/password/lib/password.php',
			'/libraries/compat/password/lib/version_test.php',
			'/libraries/compat/password/index.html',
			'/libraries/compat/password/LICENSE.md',
			'/libraries/compat/index.html',
			'/libraries/fof/controller.php',
			'/libraries/fof/dispatcher.php',
			'/libraries/fof/inflector.php',
			'/libraries/fof/input.php',
			'/libraries/fof/model.php',
			'/libraries/fof/query.abstract.php',
			'/libraries/fof/query.element.php',
			'/libraries/fof/query.mysql.php',
			'/libraries/fof/query.mysqli.php',
			'/libraries/fof/query.sqlazure.php',
			'/libraries/fof/query.sqlsrv.php',
			'/libraries/fof/render.abstract.php',
			'/libraries/fof/render.webdata.php',
			'/libraries/fof/render.webdata3.php',
			'/libraries/fof/render.strapper.php',
			'/libraries/fof/string.utils.php',
			'/libraries/fof/table.php',
			'/libraries/fof/template.utils.php',
			'/libraries/fof/toolbar.php',
			'/libraries/fof/view.csv.php',
			'/libraries/fof/view.html.php',
			'/libraries/fof/view.json.php',
			'/libraries/fof/view.php',
			'/libraries/framework/Webdata/Application/Cli/Output/Processor/ColorProcessor.php',
			'/libraries/framework/Webdata/Application/Cli/Output/Processor/ProcessorInterface.php',
			'/libraries/framework/Webdata/Application/Cli/Output/Stdout.php',
			'/libraries/framework/Webdata/Application/Cli/Output/Xml.php',
			'/libraries/framework/Webdata/Application/Cli/CliOutput.php',
			'/libraries/framework/Webdata/Application/Cli/ColorProcessor.php',
			'/libraries/framework/Webdata/Application/Cli/ColorStyle.php',
			'/libraries/framework/index.html',
			'/libraries/framework/Webdata/DI/Exception/DependencyResolutionException.php',
			'/libraries/framework/Webdata/DI/Exception/index.html',
			'/libraries/framework/Webdata/DI/Container.php',
			'/libraries/framework/Webdata/DI/ContainerAwareInterface.php',
			'/libraries/framework/Webdata/DI/index.html',
			'/libraries/framework/Webdata/DI/ServiceProviderInterface.php',
			'/libraries/framework/Webdata/Registry/Format/index.html',
			'/libraries/framework/Webdata/Registry/Format/Ini.php',
			'/libraries/framework/Webdata/Registry/Format/Json.php',
			'/libraries/framework/Webdata/Registry/Format/Php.php',
			'/libraries/framework/Webdata/Registry/Format/Xml.php',
			'/libraries/framework/Webdata/Registry/Format/Yaml.php',
			'/libraries/framework/Webdata/Registry/AbstractRegistryFormat.php',
			'/libraries/framework/Webdata/Registry/index.html',
			'/libraries/framework/Webdata/Registry/Registry.php',
			'/libraries/framework/Symfony/Component/Yaml/Exception/DumpException.php',
			'/libraries/framework/Symfony/Component/Yaml/Exception/ExceptionInterface.php',
			'/libraries/framework/Symfony/Component/Yaml/Exception/index.html',
			'/libraries/framework/Symfony/Component/Yaml/Exception/ParseException.php',
			'/libraries/framework/Symfony/Component/Yaml/Exception/RuntimeException.php',
			'/libraries/framework/Symfony/Component/Yaml/Dumper.php',
			'/libraries/framework/Symfony/Component/Yaml/Escaper.php',
			'/libraries/framework/Symfony/Component/Yaml/index.html',
			'/libraries/framework/Symfony/Component/Yaml/Inline.php',
			'/libraries/framework/Symfony/Component/Yaml/LICENSE',
			'/libraries/framework/Symfony/Component/Yaml/Parser.php',
			'/libraries/framework/Symfony/Component/Yaml/Unescaper.php',
			'/libraries/framework/Symfony/Component/Yaml/Yaml.php',
			'/libraries/webdata/string/inflector.php',
			'/libraries/webdata/string/normalise.php',
			'/libraries/phpmailer/language/index.html',
			'/libraries/phpmailer/language/phpmailer.lang-webdata.php',
			'/libraries/phpmailer/index.html',
			'/libraries/phpmailer/LICENSE',
			'/libraries/phpmailer/phpmailer.php',
			'/libraries/phpmailer/pop.php',
			'/libraries/phpmailer/smtp.php',
			'/media/editors/codemirror/css/ambiance.css',
			'/media/editors/codemirror/css/codemirror.css',
			'/media/editors/codemirror/css/configuration.css',
			'/media/editors/codemirror/css/index.html',
			'/media/editors/codemirror/js/brace-fold.js',
			'/media/editors/codemirror/js/clike.js',
			'/media/editors/codemirror/js/closebrackets.js',
			'/media/editors/codemirror/js/closetag.js',
			'/media/editors/codemirror/js/codemirror.js',
			'/media/editors/codemirror/js/css.js',
			'/media/editors/codemirror/js/foldcode.js',
			'/media/editors/codemirror/js/foldgutter.js',
			'/media/editors/codemirror/js/fullscreen.js',
			'/media/editors/codemirror/js/htmlmixed.js',
			'/media/editors/codemirror/js/indent-fold.js',
			'/media/editors/codemirror/js/index.html',
			'/media/editors/codemirror/js/javascript.js',
			'/media/editors/codemirror/js/less.js',
			'/media/editors/codemirror/js/matchbrackets.js',
			'/media/editors/codemirror/js/matchtags.js',
			'/media/editors/codemirror/js/php.js',
			'/media/editors/codemirror/js/xml-fold.js',
			'/media/editors/codemirror/js/xml.js',
			'/media/editors/tinymce/skins/lightgray/fonts/icomoon.svg',
			'/media/editors/tinymce/skins/lightgray/fonts/icomoon.ttf',
			'/media/editors/tinymce/skins/lightgray/fonts/icomoon.woff',
			'/media/editors/tinymce/skins/lightgray/fonts/icomoon-small.eot',
			'/media/editors/tinymce/skins/lightgray/fonts/icomoon-small.svg',
			'/media/editors/tinymce/skins/lightgray/fonts/icomoon-small.ttf',
			'/media/editors/tinymce/skins/lightgray/fonts/icomoon-small.woff',
			'/media/editors/tinymce/skins/lightgray/fonts/readme.md',
			'/media/editors/tinymce/skins/lightgray/fonts/tinymce.dev.svg',
			'/media/editors/tinymce/skins/lightgray/fonts/tinymce-small.dev.svg',
			'/media/editors/tinymce/skins/lightgray/img/wline.gif',
			'/plugins/editors/codemirror/styles.css',
			'/plugins/editors/codemirror/styles.min.css',
			// Webdata! 3.4.1
			'/libraries/webdata/environment/request.php',
			'/media/editors/tinymce/templates/template_list.js',
			'/media/editors/codemirror/lib/addons-uncompressed.js',
			'/media/editors/codemirror/lib/codemirror-uncompressed.css',
			'/media/editors/codemirror/lib/codemirror-uncompressed.js',
			'/administrator/help/en-GB/Components_Banners_Banners.html',
			'/administrator/help/en-GB/Components_Banners_Banners_Edit.html',
			'/administrator/help/en-GB/Components_Banners_Categories.html',
			'/administrator/help/en-GB/Components_Banners_Category_Edit.html',
			'/administrator/help/en-GB/Components_Banners_Clients.html',
			'/administrator/help/en-GB/Components_Banners_Clients_Edit.html',
			'/administrator/help/en-GB/Components_Banners_Tracks.html',
			'/administrator/help/en-GB/Components_Contact_Categories.html',
			'/administrator/help/en-GB/Components_Contact_Category_Edit.html',
			'/administrator/help/en-GB/Components_Contacts_Contacts.html',
			'/administrator/help/en-GB/Components_Contacts_Contacts_Edit.html',
			'/administrator/help/en-GB/Components_Content_Categories.html',
			'/administrator/help/en-GB/Components_Content_Category_Edit.html',
			'/administrator/help/en-GB/Components_Messaging_Inbox.html',
			'/administrator/help/en-GB/Components_Messaging_Read.html',
			'/administrator/help/en-GB/Components_Messaging_Write.html',
			'/administrator/help/en-GB/Components_Newsfeeds_Categories.html',
			'/administrator/help/en-GB/Components_Newsfeeds_Category_Edit.html',
			'/administrator/help/en-GB/Components_Newsfeeds_Feeds.html',
			'/administrator/help/en-GB/Components_Newsfeeds_Feeds_Edit.html',
			'/administrator/help/en-GB/Components_Redirect_Manager.html',
			'/administrator/help/en-GB/Components_Redirect_Manager_Edit.html',
			'/administrator/help/en-GB/Components_Search.html',
			'/administrator/help/en-GB/Components_Weblinks_Categories.html',
			'/administrator/help/en-GB/Components_Weblinks_Category_Edit.html',
			'/administrator/help/en-GB/Components_Weblinks_Links.html',
			'/administrator/help/en-GB/Components_Weblinks_Links_Edit.html',
			'/administrator/help/en-GB/Content_Article_Manager.html',
			'/administrator/help/en-GB/Content_Article_Manager_Edit.html',
			'/administrator/help/en-GB/Content_Featured_Articles.html',
			'/administrator/help/en-GB/Content_Media_Manager.html',
			'/administrator/help/en-GB/Extensions_Extension_Manager_Discover.html',
			'/administrator/help/en-GB/Extensions_Extension_Manager_Install.html',
			'/administrator/help/en-GB/Extensions_Extension_Manager_Manage.html',
			'/administrator/help/en-GB/Extensions_Extension_Manager_Update.html',
			'/administrator/help/en-GB/Extensions_Extension_Manager_Warnings.html',
			'/administrator/help/en-GB/Extensions_Language_Manager_Content.html',
			'/administrator/help/en-GB/Extensions_Language_Manager_Edit.html',
			'/administrator/help/en-GB/Extensions_Language_Manager_Installed.html',
			'/administrator/help/en-GB/Extensions_Module_Manager.html',
			'/administrator/help/en-GB/Extensions_Module_Manager_Edit.html',
			'/administrator/help/en-GB/Extensions_Plugin_Manager.html',
			'/administrator/help/en-GB/Extensions_Plugin_Manager_Edit.html',
			'/administrator/help/en-GB/Extensions_Template_Manager_Styles.html',
			'/administrator/help/en-GB/Extensions_Template_Manager_Styles_Edit.html',
			'/administrator/help/en-GB/Extensions_Template_Manager_Templates.html',
			'/administrator/help/en-GB/Extensions_Template_Manager_Templates_Edit.html',
			'/administrator/help/en-GB/Extensions_Template_Manager_Templates_Edit_Source.html',
			'/administrator/help/en-GB/Glossary.html',
			'/administrator/help/en-GB/Menus_Menu_Item_Manager.html',
			'/administrator/help/en-GB/Menus_Menu_Item_Manager_Edit.html',
			'/administrator/help/en-GB/Menus_Menu_Manager.html',
			'/administrator/help/en-GB/Menus_Menu_Manager_Edit.html',
			'/administrator/help/en-GB/Site_Global_Configuration.html',
			'/administrator/help/en-GB/Site_Maintenance_Clear_Cache.html',
			'/administrator/help/en-GB/Site_Maintenance_Global_Check-in.html',
			'/administrator/help/en-GB/Site_Maintenance_Purge_Expired_Cache.html',
			'/administrator/help/en-GB/Site_System_Information.html',
			'/administrator/help/en-GB/Start_Here.html',
			'/administrator/help/en-GB/Users_Access_Levels.html',
			'/administrator/help/en-GB/Users_Access_Levels_Edit.html',
			'/administrator/help/en-GB/Users_Debug_Users.html',
			'/administrator/help/en-GB/Users_Groups.html',
			'/administrator/help/en-GB/Users_Groups_Edit.html',
			'/administrator/help/en-GB/Users_Mass_Mail_Users.html',
			'/administrator/help/en-GB/Users_User_Manager.html',
			'/administrator/help/en-GB/Users_User_Manager_Edit.html',
			'/administrator/components/com_config/views/index.html',
			'/administrator/components/com_config/views/application/index.html',
			'/administrator/components/com_config/views/application/view.html.php',
			'/administrator/components/com_config/views/application/tmpl/default.php',
			'/administrator/components/com_config/views/application/tmpl/default_cache.php',
			'/administrator/components/com_config/views/application/tmpl/default_cookie.php',
			'/administrator/components/com_config/views/application/tmpl/default_database.php',
			'/administrator/components/com_config/views/application/tmpl/default_debug.php',
			'/administrator/components/com_config/views/application/tmpl/default_filters.php',
			'/administrator/components/com_config/views/application/tmpl/default_ftp.php',
			'/administrator/components/com_config/views/application/tmpl/default_ftplogin.php',
			'/administrator/components/com_config/views/application/tmpl/default_locale.php',
			'/administrator/components/com_config/views/application/tmpl/default_mail.php',
			'/administrator/components/com_config/views/application/tmpl/default_metadata.php',
			'/administrator/components/com_config/views/application/tmpl/default_navigation.php',
			'/administrator/components/com_config/views/application/tmpl/default_permissions.php',
			'/administrator/components/com_config/views/application/tmpl/default_seo.php',
			'/administrator/components/com_config/views/application/tmpl/default_server.php',
			'/administrator/components/com_config/views/application/tmpl/default_session.php',
			'/administrator/components/com_config/views/application/tmpl/default_site.php',
			'/administrator/components/com_config/views/application/tmpl/default_system.php',
			'/administrator/components/com_config/views/application/tmpl/index.html',
			'/administrator/components/com_config/views/close/index.html',
			'/administrator/components/com_config/views/close/view.html.php',
			'/administrator/components/com_config/views/component/index.html',
			'/administrator/components/com_config/views/component/view.html.php',
			'/administrator/components/com_config/views/component/tmpl/default.php',
			'/administrator/components/com_config/views/component/tmpl/index.html',
			'/administrator/components/com_config/models/fields/filters.php',
			'/administrator/components/com_config/models/fields/index.html',
			'/administrator/components/com_config/models/forms/application.xml',
			'/administrator/components/com_config/models/forms/index.html',
			// Webdata 3.4.2
			'/libraries/composer_autoload.php',
			'/administrator/templates/hathor/html/com_categories/categories/default_batch.php',
			'/administrator/templates/hathor/html/com_tags/tags/default_batch.php',
			'/media/editors/codemirror/mode/clike/scala.html',
			'/media/editors/codemirror/mode/css/less.html',
			'/media/editors/codemirror/mode/css/less_test.js',
			'/media/editors/codemirror/mode/css/scss.html',
			'/media/editors/codemirror/mode/css/scss_test.js',
			'/media/editors/codemirror/mode/css/test.js',
			'/media/editors/codemirror/mode/gfm/test.js',
			'/media/editors/codemirror/mode/haml/test.js',
			'/media/editors/codemirror/mode/javascript/json-ld.html',
			'/media/editors/codemirror/mode/javascript/test.js',
			'/media/editors/codemirror/mode/javascript/typescript.html',
			'/media/editors/codemirror/mode/markdown/test.js',
			'/media/editors/codemirror/mode/php/test.js',
			'/media/editors/codemirror/mode/ruby/test.js',
			'/media/editors/codemirror/mode/shell/test.js',
			'/media/editors/codemirror/mode/slim/test.js',
			'/media/editors/codemirror/mode/stex/test.js',
			'/media/editors/codemirror/mode/textile/test.js',
			'/media/editors/codemirror/mode/verilog/test.js',
			'/media/editors/codemirror/mode/xml/test.js',
			'/media/editors/codemirror/mode/xquery/test.js',
			// Webdata 3.4.3
			'/libraries/classloader.php',
			'/libraries/ClassLoader.php',
			// Webdata 3.4.6
			'/components/com_wrapper/views/wrapper/metadata.xml',
			// Webdata 3.5.0
			'/media/com_webdataupdate/default.js',
			'/media/com_webdataupdate/encryption.js',
			'/media/com_webdataupdate/json2.js',
			'/media/com_webdataupdate/update.js',
			'/media/com_finder/css/finder-rtl.css',
			'/media/com_finder/css/selectfilter.css',
			'/media/com_finder/css/sliderfilter.css',
			'/media/com_finder/js/sliderfilter.js',
			'/media/editors/codemirror/mode/kotlin/kotlin.js',
			'/media/editors/codemirror/mode/kotlin/kotlin.min.js',
			'/media/editors/tinymce/plugins/compat3x/editable_selects.js',
			'/media/editors/tinymce/plugins/compat3x/form_utils.js',
			'/media/editors/tinymce/plugins/compat3x/mctabs.js',
			'/media/editors/tinymce/plugins/compat3x/tiny_mce_popup.js',
			'/media/editors/tinymce/plugins/compat3x/validate.js',
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml/Dumper.php',
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml/Escaper.php',
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml/Inline.php',
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml/LICENSE',
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml/Parser.php',
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml/Unescaper.php',
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml/Yaml.php',
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml/Exception/DumpException.php',
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml/Exception/ExceptionInterface.php',
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml/Exception/ParseException.php',
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml/Exception/RuntimeException.php',
			'/libraries/vendor/phpmailer/phpmailer/extras/class.html2text.php',
			'/libraries/webdata/document/error/error.php',
			'/libraries/webdata/document/feed/feed.php',
			'/libraries/webdata/document/html/html.php',
			'/libraries/webdata/document/image/image.php',
			'/libraries/webdata/document/json/json.php',
			'/libraries/webdata/document/opensearch/opensearch.php',
			'/libraries/webdata/document/raw/raw.php',
			'/libraries/webdata/document/xml/xml.php',
			'/plugins/editors/tinymce/fields/skins.php',
			'/plugins/user/profile/fields/dob.php',
			'/plugins/user/profile/fields/tos.php',
			'/administrator/components/com_installer/views/languages/tmpl/default_filter.php',
			'/administrator/components/com_webdataupdate/helpers/download.php',
			'/administrator/components/com_config/controller/application/refreshhelp.php',
			'/administrator/components/com_media/models/forms/index.html',
			// Webdata 3.6.0
			'/libraries/simplepie/README.txt',
			'/libraries/simplepie/simplepie.php',
			'/libraries/simplepie/LICENSE.txt',
			'/libraries/simplepie/idn/LICENCE',
			'/libraries/simplepie/idn/ReadMe.txt',
			'/libraries/simplepie/idn/idna_convert.class.php',
			'/libraries/simplepie/idn/npdata.ser',
			'/administrator/manifests/libraries/simplepie.xml',
			'/administrator/templates/isis/js/jquery.js',
			'/administrator/templates/isis/js/bootstrap.min.js',
			'/media/system/js/permissions.min.js',
			'/libraries/platform.php',
			'/plugins/user/profile/fields/tos.php',
			'/libraries/webdata/application/web/client.php',
			// Webdata! 3.6.1
			'/libraries/webdata/database/iterator/azure.php',
			'/media/editors/tinymce/skins/lightgray/fonts/icomoon.eot',
			// Webdata! 3.6.3
			'/media/editors/codemirror/mode/jade/jade.js',
			'/media/editors/codemirror/mode/jade/jade.min.js',
			// Webdata 3.7.0
			'/libraries/webdata/user/authentication.php',
			'/libraries/platform.php',
			'/libraries/webdata/data/data.php',
			'/libraries/webdata/data/dumpable.php',
			'/libraries/webdata/data/set.php',
			'/administrator/components/com_banners/views/banners/tmpl/default_batch.php',
			'/administrator/components/com_categories/views/category/tmpl/edit_extrafields.php',
			'/administrator/components/com_categories/views/category/tmpl/edit_options.php',
			'/administrator/components/com_categories/views/categories/tmpl/default_batch.php',
			'/administrator/components/com_content/views/articles/tmpl/default_batch.php',
			'/administrator/components/com_menus/views/items/tmpl/default_batch.php',
			'/administrator/components/com_modules/views/modules/tmpl/default_batch.php',
			'/administrator/components/com_newsfeeds/views/newsfeeds/tmpl/default_batch.php',
			'/administrator/components/com_redirect/views/links/tmpl/default_batch.php',
			'/administrator/components/com_tags/views/tags/tmpl/default_batch.php',
			'/administrator/components/com_users/views/users/tmpl/default_batch.php',
			'/components/com_contact/metadata.xml',
			'/components/com_contact/views/category/metadata.xml',
			'/components/com_contact/views/contact/metadata.xml',
			'/components/com_contact/views/featured/metadata.xml',
			'/components/com_content/metadata.xml',
			'/components/com_content/views/archive/metadata.xml',
			'/components/com_content/views/article/metadata.xml',
			'/components/com_content/views/categories/metadata.xml',
			'/components/com_content/views/category/metadata.xml',
			'/components/com_content/views/featured/metadata.xml',
			'/components/com_content/views/form/metadata.xml',
			'/components/com_finder/views/search/metadata.xml',
			'/components/com_mailto/views/mailto/metadata.xml',
			'/components/com_mailto/views/sent/metadata.xml',
			'/components/com_newsfeeds/metadata.xml',
			'/components/com_newsfeeds/views/category/metadata.xml',
			'/components/com_newsfeeds/views/newsfeed/metadata.xml',
			'/components/com_search/views/search/metadata.xml',
			'/components/com_tags/metadata.xml',
			'/components/com_tags/views/tag/metadata.xml',
			'/components/com_users/metadata.xml',
			'/components/com_users/views/login/metadata.xml',
			'/components/com_users/views/profile/metadata.xml',
			'/components/com_users/views/registration/metadata.xml',
			'/components/com_users/views/remind/metadata.xml',
			'/components/com_users/views/reset/metadata.xml',
			'/components/com_wrapper/metadata.xml',
			'/administrator/components/com_cache/layouts/webdata/searchtools/default/bar.php',
			'/administrator/components/com_cache/layouts/webdata/searchtools/default.php',
			'/administrator/components/com_languages/layouts/webdata/searchtools/default/bar.php',
			'/administrator/components/com_languages/layouts/webdata/searchtools/default.php',
			'/administrator/components/com_modules/layouts/webdata/searchtools/default/bar.php',
			'/administrator/components/com_modules/layouts/webdata/searchtools/default.php',
			'/administrator/components/com_templates/layouts/webdata/searchtools/default/bar.php',
			'/administrator/components/com_templates/layouts/webdata/searchtools/default.php',
			'/administrator/modules/mod_menu/tmpl/default_enabled.php',
			'/administrator/modules/mod_menu/tmpl/default_disabled.php',
			'/administrator/templates/hathor/html/mod_menu/default_enabled.php',
			'/administrator/components/com_users/models/fields/components.php',
			'/administrator/components/com_installer/controllers/languages.php',
			'/administrator/components/com_media/views/medialist/tmpl/thumbs_doc.php',
			'/administrator/components/com_media/views/medialist/tmpl/thumbs_folder.php',
			'/administrator/components/com_media/views/medialist/tmpl/thumbs_img.php',
			'/administrator/components/com_media/views/medialist/tmpl/thumbs_video.php',
			'/media/editors/none/none.js',
			'/media/editors/none/none.min.js',
			'/media/editors/tinymce/plugins/media/moxieplayer.swf',
			'/media/system/js/tiny-close.js',
			'/media/system/js/tiny-close.min.js',
			'/administrator/components/com_messages/layouts/toolbar/mysettings.php',
			'/media/editors/tinymce/plugins/jdragdrop/plugin.js',
			'/media/editors/tinymce/plugins/jdragdrop/plugin.min.js',
			// Webdata 3.7.1
			'/media/editors/tinymce/langs/uk-UA.js',
			'/media/system/js/fields/calendar-locales/zh.js',
			// Webdata 3.7.3
			'/administrator/components/com_admin/postinstall/phpversion.php',
			'/components/com_content/layouts/field/prepare/modal_article.php',
		);

		// TODO There is an issue while deleting folders using the ftp mode
		$folders = array(
			'/administrator/components/com_admin/sql/updates/sqlsrv',
			'/media/com_finder/images/mime',
			'/media/com_finder/images',
			'/components/com_media/helpers',
			// Webdata 3.0
			'/administrator/components/com_contact/elements',
			'/administrator/components/com_content/elements',
			'/administrator/components/com_newsfeeds/elements',
			'/administrator/components/com_templates/views/prevuuw/tmpl',
			'/administrator/components/com_templates/views/prevuuw',
			'/libraries/cms/controller',
			'/libraries/cms/model',
			'/libraries/cms/view',
			'/libraries/webdata/application/cli',
			'/libraries/webdata/application/component',
			'/libraries/webdata/application/input',
			'/libraries/webdata/application/module',
			'/libraries/webdata/cache/storage/helpers',
			'/libraries/webdata/database/table',
			'/libraries/webdata/database/database',
			'/libraries/webdata/error',
			'/libraries/webdata/filesystem/archive',
			'/libraries/webdata/html/html',
			'/libraries/webdata/html/toolbar',
			'/libraries/webdata/html/toolbar/button',
			'/libraries/webdata/html/parameter',
			'/libraries/webdata/html/parameter/element',
			'/libraries/webdata/image/filters',
			'/libraries/webdata/log/loggers',
			// Webdata! 3.1
			'/libraries/webdata/form/rules',
			'/libraries/webdata/html/language/en-GB',
			'/libraries/webdata/html/language',
			'/libraries/webdata/html',
			'/libraries/webdata/installer/adapters',
			'/libraries/webdata/installer',
			'/libraries/webdata/pagination',
			'/libraries/legacy/html',
			'/libraries/legacy/menu',
			'/libraries/legacy/pathway',
			'/media/system/swf/',
			'/media/editors/tinymce/jscripts',
			// Webdata! 3.2
			'/libraries/webdata/plugin',
			'/libraries/legacy/component',
			'/libraries/legacy/module',
			'/administrator/components/com_weblinks/models/fields',
			'/plugins/user/webdata/postinstall',
			'/libraries/webdata/registry/format',
			'/libraries/webdata/registry',
			// Webdata! 3.3
			'/plugins/user/profile/fields',
			'/media/editors/tinymce/plugins/compat3x',
			// Webdata! 3.4
			'/administrator/components/com_tags/helpers/html',
			'/administrator/components/com_tags/models/fields',
			'/administrator/templates/hathor/html/com_finder/filter',
			'/administrator/templates/hathor/html/com_finder/statistics',
			'/libraries/compat/password/lib',
			'/libraries/compat/password',
			'/libraries/compat',
			'/libraries/framework/Webdata/Application/Cli/Output/Processor',
			'/libraries/framework/Webdata/Application/Cli/Output',
			'/libraries/framework/Webdata/Application/Cli',
			'/libraries/framework/Webdata/Application',
			'/libraries/framework/Webdata/DI/Exception',
			'/libraries/framework/Webdata/DI',
			'/libraries/framework/Webdata/Registry/Format',
			'/libraries/framework/Webdata/Registry',
			'/libraries/framework/Webdata',
			'/libraries/framework/Symfony/Component/Yaml/Exception',
			'/libraries/framework/Symfony/Component/Yaml',
			'/libraries/framework',
			'/libraries/phpmailer/language',
			'/libraries/phpmailer',
			'/media/editors/codemirror/css',
			'/media/editors/codemirror/js',
			'/media/com_banners',
			// Webdata! 3.4.1
			'/administrator/components/com_config/views',
			'/administrator/components/com_config/models/fields',
			'/administrator/components/com_config/models/forms',
			// Webdata! 3.4.2
			'/media/editors/codemirror/mode/smartymixed',
			// Webdata! 3.5
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml/Exception',
			'/libraries/vendor/symfony/yaml/Symfony/Component/Yaml',
			'/libraries/vendor/symfony/yaml/Symfony/Component',
			'/libraries/vendor/symfony/yaml/Symfony',
			'/libraries/webdata/document/error',
			'/libraries/webdata/document/image',
			'/libraries/webdata/document/json',
			'/libraries/webdata/document/opensearch',
			'/libraries/webdata/document/raw',
			'/libraries/webdata/document/xml',
			'/administrator/components/com_media/models/forms',
			'/media/editors/codemirror/mode/kotlin',
			'/media/editors/tinymce/plugins/compat3x',
			'/plugins/editors/tinymce/fields',
			'/plugins/user/profile/fields',
			// Webdata 3.6
			'/libraries/simplepie/idn',
			'/libraries/simplepie',
			// Webdata! 3.6.3
			'/media/editors/codemirror/mode/jade',
			// Webdata! 3.7.0
			'/libraries/webdata/data',
			'/administrator/components/com_cache/layouts/webdata/searchtools/default',
			'/administrator/components/com_cache/layouts/webdata/searchtools',
			'/administrator/components/com_cache/layouts/webdata',
			'/administrator/components/com_cache/layouts',
			'/administrator/components/com_languages/layouts/webdata/searchtools/default',
			'/administrator/components/com_languages/layouts/webdata/searchtools',
			'/administrator/components/com_languages/layouts/webdata',
			'/administrator/components/com_languages/layouts',
			'/administrator/components/com_modules/layouts/webdata/searchtools/default',
			'/administrator/components/com_modules/layouts/webdata/searchtools',
			'/administrator/components/com_modules/layouts/webdata',
			'/administrator/components/com_templates/layouts/webdata/searchtools/default',
			'/administrator/components/com_templates/layouts/webdata/searchtools',
			'/administrator/components/com_templates/layouts/webdata',
			'/administrator/components/com_templates/layouts',
			'/administrator/templates/hathor/html/mod_menu',
			'/administrator/components/com_messages/layouts/toolbar',
			'/administrator/components/com_messages/layouts',
			// Webdata! 3.7.4
			'/components/com_fields/controllers',
		);

		jimport('webdata.filesystem.file');

		foreach ($files as $file)
		{
			if (JFile::exists(JPATH_ROOT . $file) && !JFile::delete(JPATH_ROOT . $file))
			{
				echo JText::sprintf('FILES_JOOMLA_ERROR_FILE_FOLDER', $file) . '<br />';
			}
		}

		jimport('webdata.filesystem.folder');

		foreach ($folders as $folder)
		{
			if (JFolder::exists(JPATH_ROOT . $folder) && !JFolder::delete(JPATH_ROOT . $folder))
			{
				echo JText::sprintf('FILES_JOOMLA_ERROR_FILE_FOLDER', $folder) . '<br />';
			}
		}

		/*
		 * Needed for updates post-3.4
		 * If com_weblinks doesn't exist then assume we can delete the weblinks package manifest (included in the update packages)
		 */
		if (!JFile::exists(JPATH_ROOT . '/administrator/components/com_weblinks/weblinks.php')
			&& JFile::exists(JPATH_ROOT . '/administrator/manifests/packages/pkg_weblinks.xml'))
		{
			JFile::delete(JPATH_ROOT . '/administrator/manifests/packages/pkg_weblinks.xml');
		}
	}

	/**
	 * Clears the RAD layer's table cache.
	 *
	 * The cache vastly improves performance but needs to be cleared every time you update the database schema.
	 *
	 * @return  void
	 *
	 * @since   3.2
	 */
	protected function clearRadCache()
	{
		jimport('webdata.filesystem.file');

		if (JFile::exists(JPATH_ROOT . '/cache/fof/cache.php'))
		{
			JFile::delete(JPATH_ROOT . '/cache/fof/cache.php');
		}
	}

	/**
	 * Method to create assets for newly installed components
	 *
	 * @param   JInstaller  $installer  The class calling this method
	 *
	 * @return  boolean
	 *
	 * @since   3.2
	 */
	public function updateAssets($installer)
	{
		// List all components added since 1.6
		$newComponents = array(
			'com_finder',
			'com_webdataupdate',
			'com_tags',
			'com_contenthistory',
			'com_ajax',
			'com_postinstall',
			'com_fields',
			'com_associations',
		);

		foreach ($newComponents as $component)
		{
			/** @var JTableAsset $asset */
			$asset = JTable::getInstance('Asset');

			if ($asset->loadByName($component))
			{
				continue;
			}

			$asset->name      = $component;
			$asset->parent_id = 1;
			$asset->rules     = '{}';
			$asset->title     = $component;
			$asset->setLocation(1, 'last-child');

			if (!$asset->store())
			{
				// Install failed, roll back changes
				$installer->abort(JText::sprintf('JLIB_INSTALLER_ABORT_COMP_INSTALL_ROLLBACK', $asset->stderr(true)));

				return false;
			}
		}

		return true;
	}

	/**
	 * If we migrated the session from the previous system, flush all the active sessions.
	 * Otherwise users will be logged in, but not able to do anything since they don't have
	 * a valid session
	 *
	 * @return  boolean
	 */
	public function flushSessions()
	{
		/**
		 * The session may have not been started yet (e.g. CLI-based Webdata! update scripts). Let's make sure we do
		 * have a valid session.
		 */
		$session = JFactory::getSession();

		/**
		 * Restarting the Session require a new login for the current user so lets check if we have an active session
		 * and only restart it if not.
		 * For B/C reasons we need to use getState as isActive is not available in 2.5
		 */
		if ($session->getState() !== 'active')
		{
			$session->restart();
		}

		// If $_SESSION['__default'] is no longer set we do not have a migrated session, therefore we can quit.
		if (!isset($_SESSION['__default']))
		{
			return true;
		}

		$db = JFactory::getDbo();

		try
		{
			switch ($db->getServerType())
			{
				// MySQL database, use TRUNCATE (faster, more resilient)
				case 'mysql':
					$db->truncateTable('#__session');
					break;

				// Non-MySQL databases, use a simple DELETE FROM query
				default:
					$query = $db->getQuery(true)
						->delete($db->qn('#__session'));
					$db->setQuery($query)->execute();
					break;
			}
		}
		catch (Exception $e)
		{
			echo JText::sprintf('JLIB_DATABASE_ERROR_FUNCTION_FAILED', $e->getCode(), $e->getMessage()) . '<br />';

			return false;
		}

		return true;
	}

	/**
	 * Converts the site's database tables to support UTF-8 Multibyte.
	 *
	 * @param   boolean  $doDbFixMsg  Flag if message to be shown to check db fix
	 *
	 * @return  void
	 *
	 * @since   3.5
	 */
	public function convertTablesToUtf8mb4($doDbFixMsg = false)
	{
		$db = JFactory::getDbo();

		// This is only required for MySQL databases
		$serverType = $db->getServerType();

		if ($serverType != 'mysql')
		{
			return;
		}

		// Set required conversion status
		if ($db->hasUTF8mb4Support())
		{
			$converted = 2;
		}
		else
		{
			$converted = 1;
		}

		// Check conversion status in database
		$db->setQuery('SELECT ' . $db->quoteName('converted')
			. ' FROM ' . $db->quoteName('#__utf8_conversion')
		);

		try
		{
			$convertedDB = $db->loadResult();
		}
		catch (Exception $e)
		{
			// Render the error message from the Exception object
			JFactory::getApplication()->enqueueMessage($e->getMessage(), 'error');

			if ($doDbFixMsg)
			{
				// Show an error message telling to check database problems
				JFactory::getApplication()->enqueueMessage(JText::_('JLIB_DATABASE_ERROR_DATABASE_UPGRADE_FAILED'), 'error');
			}

			return;
		}

		// Nothing to do, saved conversion status from DB is equal to required
		if ($convertedDB == $converted)
		{
			return;
		}

		// Step 1: Drop indexes later to be added again with column lengths limitations at step 2
		$fileName1 = JPATH_ROOT . '/administrator/components/com_admin/sql/others/mysql/utf8mb4-conversion-01.sql';

		if (is_file($fileName1))
		{
			$fileContents1 = @file_get_contents($fileName1);
			$queries1      = $db->splitSql($fileContents1);

			if (!empty($queries1))
			{
				foreach ($queries1 as $query1)
				{
					try
					{
						$db->setQuery($query1)->execute();
					}
					catch (Exception $e)
					{
						// If the query fails we will go on. It just means the index to be dropped does not exist.
					}
				}
			}
		}

		// Step 2: Perform the index modifications and conversions
		$fileName2 = JPATH_ROOT . '/administrator/components/com_admin/sql/others/mysql/utf8mb4-conversion-02.sql';

		if (is_file($fileName2))
		{
			$fileContents2 = @file_get_contents($fileName2);
			$queries2      = $db->splitSql($fileContents2);

			if (!empty($queries2))
			{
				foreach ($queries2 as $query2)
				{
					try
					{
						$db->setQuery($db->convertUtf8mb4QueryToUtf8($query2))->execute();
					}
					catch (Exception $e)
					{
						$converted = 0;

						// Still render the error message from the Exception object
						JFactory::getApplication()->enqueueMessage($e->getMessage(), 'error');
					}
				}
			}
		}

		if ($doDbFixMsg && $converted == 0)
		{
			// Show an error message telling to check database problems
			JFactory::getApplication()->enqueueMessage(JText::_('JLIB_DATABASE_ERROR_DATABASE_UPGRADE_FAILED'), 'error');
		}

		// Set flag in database if the update is done.
		$db->setQuery('UPDATE ' . $db->quoteName('#__utf8_conversion')
			. ' SET ' . $db->quoteName('converted') . ' = ' . $converted . ';')->execute();
	}

	/**
	 * This method clean the Webdata Cache using the method `clean` from the com_cache model
	 *
	 * @return  void
	 *
	 * @since   3.5.1
	 */
	private function cleanWebdataCache()
	{
		JModelLegacy::addIncludePath(JPATH_ROOT . '/administrator/components/com_cache/models');
		$model = JModelLegacy::getInstance('cache', 'CacheModel');

		// Clean frontend cache
		$model->clean();

		// Clean admin cache
		$model->setState('client_id', 1);
		$model->clean();
	}
}
