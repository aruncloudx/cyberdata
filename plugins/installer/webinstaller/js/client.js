// Only define the Webdata namespace if not defined.
if (typeof(Webdata) === 'undefined') {
	var Webdata = {};
}

Webdata.apps = {
	view: "dashboard",
	id: 0,
	ordering: "",
//	fonturl: 'http://fonts.googleapis.com/css?family=Lato:300,400,700,300italic,400italic,700italic',
	cssfiles: [],
	jsfiles: [],
	list: 0,
	loaded: 0,
	update: false
};

Webdata.loadweb = function(url) {
	if ('' == url) { return false; }

	var pattern1 = new RegExp(apps_base_url);
	var pattern2 = new RegExp("^index\.php");
	if (!(pattern1.test(url) || pattern2.test(url))) {
		window.open(url, "_blank");
		return false;
	}

	url += '&product='+apps_product+'&release='+apps_release+'&dev_level='+apps_dev_level+'&list='+(Webdata.apps.list ? 'list' : 'grid')+'&pv='+apps_pv;
	var ordering = Webdata.apps.ordering;
	if (ordering !== "" && jQuery('#com-apps-ordering').val()) {
		ordering = jQuery('#com-apps-ordering').val();
		url += '&ordering='+ordering;
	}

	jQuery('html, body').animate({ scrollTop: 0 }, 0);
	if (jQuery('#myTabContent').length) {
		jQuery('#appsloading')
			.css("position", "absolute")
			.css("left", "0")
			.css("top", "0")
			.css("width", "100%")
			.css("height", "100%")
			.appendTo(jQuery('#web').css('position', 'relative'));
		jQuery.event.trigger("ajaxStart");
	}

	jQuery.ajax({
		url: url,
		dataType: 'jsonp',
		cache: true,
		jsonpCallback: "jedapps_jsonpcallback",
		timeout: 20000,
		success: function (response) {
			jQuery('#web-loader').hide();
			jQuery('#jed-container').html(response.data.html);
			if (!Webdata.apps.update && response.data.pluginuptodate < 1)
			{
				Webdata.apps.update = true;
				var txt = apps_obsolete;
				var btn = apps_updateavail2;
				if (response.data.pluginuptodate == 0) {
					txt = apps_updateavail1;
				}
				if (apps_is_hathor) {
					jQuery('#element-box').prepend(jQuery('<dl id="system-message"><dt class="info">info</dt><dd class="info message"><ul><li>'+txt+'&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input class="btn" type="button" value="'+btn+'" onclick="Webdata.submitbuttonInstallWebInstaller()" /></li></ul></dd></dl>'));
				}
				else {
					jQuery('#web').prepend(jQuery('<div class="alert alert-info j-jed-message" style="margin-bottom: 20px; line-height: 2em; color:#333333;">'+txt+'&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input class="btn" type="button" value="'+btn+'" onclick="Webdata.submitbuttonInstallWebInstaller()" /></div>'));
				}
			}
			if (jQuery('#myTabContent').length) {
				jQuery.event.trigger("ajaxStop");
			}
		},
		fail: function() {
			jQuery('#web-loader').hide();
			jQuery('#web-loader-error').show();
			if (jQuery('#myTabContent').length) {
				jQuery.event.trigger("ajaxStop");
			}
		},
		complete: function() {
			if(jQuery('#joomlaapsinstallatinput')) {
				jQuery('#joomlaapsinstallatinput').val(apps_installat_url);
			}
			Webdata.apps.clickforlinks();
			Webdata.apps.clicker();
			if (Webdata.apps.list) {
				jQuery(".list-view").click();
			}
			if (jQuery('#myTabContent').length) {
				jQuery.event.trigger("ajaxStop");
			}
		},
		error: function(request, status, error) {
			if (request.responseText) {
				jQuery('#web-loader-error').html(request.responseText);
			}
			jQuery('#web-loader').hide();
			jQuery('#web-loader-error').show();
			if (jQuery('#myTabContent').length) {
				jQuery.event.trigger("ajaxStop");
			}
		}
	});
	return true;
};

Webdata.webpaginate = function(url, target) {
	jQuery('#web-paginate-loader').show();
	
	jQuery.get(url, function(response) {
		jQuery('#web-paginate-loader').hide();
		jQuery('#'+target).html(response.data.html);
	}, 'jsonp').fail(function() { 
		jQuery('#web-paginate-loader').hide();
		//jQuery('#web-paginate-error').hide();
	});	
};

Webdata.installfromwebexternal = function(redirect_url) {
	var redirect_confirm = confirm('You will be redirected to the following link to complete the registration/purchase - \n'+redirect_url);
	if(true == redirect_confirm) {
		jQuery('#adminForm').attr('action', redirect_url);
		jQuery("input[name=task]").prop( "disabled", true );
		jQuery("input[name=install_directory]").prop( "disabled", true );
		jQuery("input[name=install_url]").prop( "disabled", true );
		jQuery("input[name=installtype]").prop( "disabled", true );
		jQuery("input[name=filter_search]").prop( "disabled", true );
		return true;
	}
	return false;
};

Webdata.installfromweb = function(install_url, name) {
	if ('' == install_url) {
		alert("This extension cannot be installed via the web. Please visit the developer's website to purchase/download.");
		return false;
	}
	jQuery('#install_url').val(install_url);
	jQuery('#uploadform-web-url').text(install_url);
	if (name) {
		jQuery('#uploadform-web-name').text(name);
		jQuery('#uploadform-web-name-label').show();
	} else {
		jQuery('#uploadform-web-name-label').hide();
	}
	jQuery('#jed-container').slideUp(300);
	jQuery('#uploadform-web').show();
	return true;
};

Webdata.installfromwebcancel = function() {
	jQuery('#uploadform-web').hide();
	jQuery('#jed-container').slideDown(300);
	if (Webdata.apps.list) {
		jQuery(".list-view").click();
	}
};

Webdata.installfromwebajaxsubmit = function() {
	var tail = '&view='+Webdata.apps.view;
	if (Webdata.apps.id) {
		tail += '&id='+Webdata.apps.id;
	}
	
	if (jQuery('#com-apps-searchbox').val()) {
		var value = encodeURI(jQuery('#com-apps-searchbox').val().toLowerCase().replace(/ +/g,'_').replace(/[^a-z0-9-_]/g,'').trim());
		tail += '&filter_search='+value;
	}

	var ordering = Webdata.apps.ordering;
	if (ordering !== "" && jQuery('#com-apps-ordering').val()) {
		ordering = jQuery('#com-apps-ordering').val();
	}
	if (ordering) {
		tail += '&ordering='+ordering;
	}
	Webdata.loadweb(apps_base_url+'index.php?format=json&option=com_apps'+tail);
};

Webdata.apps.clickforlinks = function () {
	jQuery('a.transcode').each(function(index, value) {
		var ajaxurl = jQuery(this).attr('href');
		(function() {
			var ajax_url = ajaxurl;
			jQuery(value).live('click', function(event){
				var pattern1 = new RegExp(apps_base_url);
				var pattern2 = new RegExp("^index\.php");
				if (pattern1.test(ajax_url) || pattern2.test(ajax_url)) {
					Webdata.apps.view = ajax_url.replace(/^.+[&\?]view=(\w+).*$/, '$1');
					if (Webdata.apps.view == 'dashboard') {
						Webdata.apps.id = 0;
					}
					else if (Webdata.apps.view == 'category') {
						Webdata.apps.id = ajax_url.replace(/^.+[&\?]id=(\d+).*$/, '$1');
					}
					event.preventDefault();
					Webdata.loadweb(apps_base_url + ajax_url);
				}
				else {
					event.preventDefault();
					Webdata.loadweb(ajax_url);
				}
			});
		})();
		jQuery(this).attr('href', '#');
	});
};

Webdata.apps.initialize = function() {
	Webdata.apps.loaded = 1;
	if (jQuery('#myTabContent').length) {
		jQuery('<div id="appsloading"></div>')
			.appendTo(jQuery('#web').css('position', 'absolute'));
		jQuery('#appsloading').ajaxStart(function() {
			jQuery('body').addClass('ifw-busy');
			jQuery(this).show();
		}).ajaxStop(function() {
			jQuery(this).hide();
			jQuery('body').removeClass('ifw-busy');
		});
	}

	Webdata.loadweb(apps_base_url+'index.php?format=json&option=com_apps&view=dashboard');
	
	Webdata.apps.clickforlinks();
	
	jQuery('#com-apps-searchbox').live('keypress', function(event){
		if(event.which == 13) {
			Webdata.apps.initiateSearch();
		}
	});
	
	jQuery('#search-reset').live('click', function(event){
		jQuery('#com-apps-searchbox').val('');
		Webdata.apps.initiateSearch();
	});

	jQuery('#com-apps-ordering').live('change', function(event){
		Webdata.apps.ordering = jQuery(this).prop("selectedIndex");
		Webdata.installfromwebajaxsubmit();
	});
	
	if (apps_installfrom_url != '') {
		Webdata.installfromweb(apps_installfrom_url);
	}
};

Webdata.apps.initiateSearch = function() {
	Webdata.apps.view = 'dashboard';
	Webdata.installfromwebajaxsubmit();
};

Webdata.apps.clicker = function() {
	jQuery(".grid-view").live("click",function() {
		Webdata.apps.list = 0;
		jQuery(".list-container").addClass("hidden");
		jQuery(".grid-container").removeClass("hidden");
		jQuery("#btn-list-view").removeClass("active");
		jQuery("#btn-grid-view").addClass("active");
	});
	jQuery(".list-view").live("click",function() {
		Webdata.apps.list = 1;
		jQuery(".grid-container").addClass("hidden");
		jQuery(".list-container").removeClass("hidden");
		jQuery("#btn-grid-view").removeClass("active");
		jQuery("#btn-list-view").addClass("active");
	});
};

Webdata.submitbutton5 = function(pressbutton)
{
	var form = document.getElementById('adminForm');
	
	// do field validation
	if (form.install_url.value != "" && form.install_url.value != "http://")
	{
		Webdata.submitbutton4();
	}
	else if (form.install_url.value == "")
	{
		alert(apps_btntxt);
	}
	else
	{
		if (!apps_is_hathor)
		{
			jQuery('#appsloading').css('display', 'block');
		}
		form.installtype.value = 'web';
		form.submit();
	}
};
