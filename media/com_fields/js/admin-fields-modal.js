/**
 * @copyright  Copyright (C) 2005 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

Webdata = window.Webdata || {};

(function(Webdata) {
	Webdata.fieldIns = function(id, editor) {
		/** Use the API, if editor supports it **/
		if (window.parent.Webdata && window.parent.Webdata.editors && window.parent.Webdata.editors.instances && window.parent.Webdata.editors.instances.hasOwnProperty(editor)) {
			window.parent.Webdata.editors.instances[editor].replaceSelection("{field " + id + "}")
		} else {
			window.parent.jInsertEditorText("{field " + id + "}", editor);
		}

		window.parent.jModalClose();
	};

	Webdata.fieldgroupIns = function(id, editor) {
		/** Use the API, if editor supports it **/
		if (window.parent.Webdata && window.parent.Webdata.editors && window.parent.Webdata.editors.instances && window.parent.Webdata.editors.instances.hasOwnProperty(editor)) {
			window.parent.Webdata.editors.instances[editor].replaceSelection("{fieldgroup " + id + "}")
		} else {
			window.parent.jInsertEditorText("{fieldgroup " + id + "}", editor);
		}

		window.parent.jModalClose();
	};
})(Webdata);
