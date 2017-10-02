/**
 * @copyright  Copyright (C) 2005 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

;(function(tinyMCE, Webdata, window, document){
	"use strict";

	// This line is for Mootools b/c
	window.getSize = window.getSize || function(){return {x: window.innerWidth, y: window.innerHeight};};

	// @deprecated 4.0 Use directly Webdata.editors.instances[editor].replaceSelection(text);
	window.jInsertEditorText = function ( text, editor ) {
		Webdata.editors.instances[editor].replaceSelection(text);
	};

	var WebdataTinyMCE = {

		/**
		 * Find all TinyMCE elements and initialize TinyMCE instance for each
		 *
		 * @param {HTMLElement}  target  Target Element where to search for the editor element
		 *
		 * @since 3.7.0
		 */
		setupEditors: function ( target ) {
			target = target || document;
			var pluginOptions = Webdata.getOptions ? Webdata.getOptions('plg_editor_tinymce', {})
					:  (Webdata.optionsStorage.plg_editor_tinymce || {}),
				editors = target.querySelectorAll('.js-editor-tinymce');

			for(var i = 0, l = editors.length; i < l; i++) {
				var editor = editors[i].querySelector('textarea');
				this.setupEditor(editor, pluginOptions);
			}
		},

		/**
		 * Initialize TinyMCE editor instance
		 *
		 * @param {HTMLElement}  element
		 * @param {Object}       pluginOptions
		 *
		 * @since 3.7.0
		 */
		setupEditor: function ( element, pluginOptions ) {
			var name = element ? element.getAttribute('name').replace(/\[\]|\]/g, '').split('[').pop() : 'default', // Get Editor name
			    tinyMCEOptions = pluginOptions ? pluginOptions.tinyMCE || {} : {},
			    defaultOptions = tinyMCEOptions['default'] || {},
			    options = tinyMCEOptions[name] ? tinyMCEOptions[name] : defaultOptions; // Check specific options by the name

			// Avoid an unexpected changes, and copy the options object
			if (options.joomlaMergeDefaults) {
				options = Webdata.extend(Webdata.extend({}, defaultOptions), options);
			} else {
				options = Webdata.extend({}, options);
			}

			if (element) {
				// We already have the Target, so reset the selector and assign given element as target
				options.selector = null;
				options.target   = element;
			}

			// @TODO: the ext-buttons should be as TinyMCE plugins, not the callback hack
			if (options.joomlaExtButtons && options.joomlaExtButtons.names && options.joomlaExtButtons.names.length) {
				options.toolbar1 += ' | ' + options.joomlaExtButtons.names.join(' ');
				var callbackString = options.joomlaExtButtons.script.join(';');
				options.setupCallbackString = options.setupCallbackString || '';
				options.setupCallbackString = options.setupCallbackString + ';' + callbackString;
				options.joomlaExtButtons = null;
			}

			if (options.setupCallbackString && !options.setup) {
				options.setup = new Function('editor', options.setupCallbackString);
			}

			// Create a new instance
			var ed = new tinyMCE.Editor(element.id, options, tinymce.EditorManager);
			ed.render();

			/** Register the editor's instance to Webdata Object */
			Webdata.editors.instances[element.id] = {
				// Required by Webdata's API for the XTD-Buttons
				'getValue': function () { return this.instance.getContent(); },
				'setValue': function (text) { return this.instance.setContent(text); },
				'replaceSelection': function (text) { return this.instance.execCommand('mceInsertContent', false, text); },
				// Some extra instance dependent
				'id': element.id,
				'instance': ed,
				'onSave': function() { if (this.instance.isHidden()) { this.instance.show()}; return '';},
			};

			/** On save **/
			document.getElementById(ed.id).form.addEventListener('submit', function() {
				Webdata.editors.instances[ed.targetElm.id].onSave();
			})
		}

	};

	Webdata.WebdataTinyMCE = WebdataTinyMCE;

	// Init on DOMContentLoaded
	document.addEventListener('DOMContentLoaded', function () {
		Webdata.WebdataTinyMCE.setupEditors();

		// Init in subform field
		if(window.jQuery) {
			jQuery(document).on('subform-row-add', function (event, row) {
				Webdata.WebdataTinyMCE.setupEditors(row);
			});
		}
	});

}(tinyMCE, Webdata, window, document));
