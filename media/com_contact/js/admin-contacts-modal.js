/**
 * @copyright  Copyright (C) 2005 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
(function() {
	"use strict";
	/**
	 * Javascript to insert the link
	 * View element calls jSelectContact when a contact is clicked
	 * jSelectContact creates the link tag, sends it to the editor,
	 * and closes the select frame.
	 */

	window.jSelectContact = function(id, title, catid, object, link, lang)
	{
		var hreflang = '', tag, editor;

		if (!Webdata.getOptions('xtd-contacts')) {
			// Something went wrong!
			window.parent.jModalClose();
			return false;
		}

		editor = Webdata.getOptions('xtd-contacts').editor;

		if (lang !== '') {
			hreflang = ' hreflang = "' + lang + '"';
		}

		tag = '<a' + hreflang + ' href="' + link + '">' + title + '</a>';

		/** Use the API, if editor supports it **/
		if (window.parent.Webdata && window.parent.Webdata.editors && window.parent.Webdata.editors.instances && window.parent.Webdata.editors.instances.hasOwnProperty(editor)) {
			window.parent.Webdata.editors.instances[editor].replaceSelection(tag)
		} else {
			window.parent.jInsertEditorText(tag, editor);
		}

		window.parent.jModalClose();
	};

	document.addEventListener('DOMContentLoaded', function(){
		// Get the elements
		var elements = document.querySelectorAll('.select-link');

		for(var i = 0, l = elements.length; l>i; i++) {
			// Listen for click event
			elements[i].addEventListener('click', function (event) {
				event.preventDefault();
				var functionName = event.target.getAttribute('data-function');

				if (functionName === 'jSelectContact') {
					// Used in xtd_contacts
					window[functionName](event.target.getAttribute('data-id'), event.target.getAttribute('data-title'), null, null, event.target.getAttribute('data-uri'), event.target.getAttribute('data-language'), null);
				} else {
					// Used in com_menus
					window.parent[functionName](event.target.getAttribute('data-id'), event.target.getAttribute('data-title'), null, null, event.target.getAttribute('data-uri'), event.target.getAttribute('data-language'), null);
				}
			})
		}
	});
})();
