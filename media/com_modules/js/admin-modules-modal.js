/**
 * @copyright  Copyright (C) 2005 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

document.addEventListener('DOMContentLoaded', function() {
	"use strict";

	/** Get the elements **/
	var modulesLinks = document.querySelectorAll('.js-module-insert'), i,
		positionsLinks = document.querySelectorAll('.js-position-insert');

	/** Assign listener for click event (for single module insertion) **/
	for (i= 0; modulesLinks.length > i; i++) {
		modulesLinks[i].addEventListener('click', function(event) {
			event.preventDefault();
			var type = event.target.getAttribute('data-module'),
				name = event.target.getAttribute('data-title'),
				editor = event.target.getAttribute('data-editor');

			/** Use the API, if editor supports it **/
			if (window.parent.Webdata && window.parent.Webdata.editors && window.parent.Webdata.editors.instances && window.parent.Webdata.editors.instances.hasOwnProperty(editor)) {
				window.parent.Webdata.editors.instances[editor].replaceSelection("{loadmodule " + type + "," + name + "}")
			} else {
				window.parent.jInsertEditorText("{loadmodule " + type + "," + name + "}", editor);
			}

			window.parent.jModalClose();
		});
	}

	/** Assign listener for click event (for position insertion) **/
	for (i= 0; positionsLinks.length > i; i++) {
		positionsLinks[i].addEventListener('click', function(event) {
			event.preventDefault();
			var position = event.target.getAttribute('data-position'),
				editor = event.target.getAttribute('data-editor');

			/** Use the API, if editor supports it **/
			if (window.Webdata && window.Webdata.editors && Webdata.editors.instances && Webdata.editors.instances.hasOwnProperty(editor)) {
				Webdata.editors.instances[editor].replaceSelection("{loadposition " + position + "}")
			} else {
				window.parent.jInsertEditorText("{loadposition " + position + "}", editor);
			}

			window.parent.jModalClose();
		});
	}
});
