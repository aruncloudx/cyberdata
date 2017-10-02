/**
 * @copyright  Copyright (C) 2005 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

window.insertReadmore = function(editor) {
	"use strict";
	if (!Webdata.getOptions('xtd-readmore')) {
		// Something went wrong!
		return false;
	}

	var content, options = window.Webdata.getOptions('xtd-readmore');

	if (window.Webdata && window.Webdata.editors && window.Webdata.editors.instances && window.Webdata.editors.instances.hasOwnProperty(editor)) {
		content = window.Webdata.editors.instances[editor].getValue();
	} else {
		content = (new Function('return ' + options.editor))();
	}

	if (content.match(/<hr\s+id=("|')system-readmore("|')\s*\/*>/i)) {
		alert(options.exists);
		return false;
	} else {
		/** Use the API, if editor supports it **/
		if (window.Webdata && window.Webdata.editors && window.Webdata.editors.instances && window.Webdata.editors.instances.hasOwnProperty(editor)) {
			window.Webdata.editors.instances[editor].replaceSelection('<hr id="system-readmore" />');
		} else {
			window.jInsertEditorText('<hr id="system-readmore" />', editor);
		}
	}
};
