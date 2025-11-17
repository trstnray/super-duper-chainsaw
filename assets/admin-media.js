(function($){
	if (!window.wp || !wp.media || !wp.media.view) { return; }
	var label = (window.WAAFT && WAAFT.i18nButton) ? WAAFT.i18nButton : 'Use filename as ALT';

	function addButton(view){
		var target = view.$el.find('.setting[data-setting="alt"]');
		if (!target.length || target.find('.waaft-btn').length) { return; }
		var btn = $('<button type="button" class="button button-small waaft-btn" style="margin-top:4px;">'+label+'</button>');
		btn.on('click', function(){
			try {
				var model = view.model || (view.controller && view.controller.state().get('selection').first());
				if (!model) { return; }
				var filename = (model.get('filename') || '').replace(/\.[^/.]+$/, '');
				filename = filename.normalize ? filename.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : filename;
				filename = filename.replace(/[_\-.]+/g, ' ')
					.replace(/[^A-Za-z0-9 ]+/g, '')
					.replace(/\s+/g, ' ')
					.trim();
				target.find('input[type="text"]').val(filename).trigger('change');
			} catch(e){}
		});
		target.append(btn);
	}

	function wrap(proto){
		if (!proto) { return; }
		var original = proto.render;
		proto.render = function(){
			var out = original.apply(this, arguments);
			setTimeout(addButton.bind(null, this), 0);
			return out;
		};
	}

	$(function(){
		wrap(wp.media.view.Attachment && wp.media.view.Attachment.Details && wp.media.view.Attachment.Details.prototype);
		wrap(wp.media.view.AttachmentCompat && wp.media.view.AttachmentCompat.prototype);
	});
})(jQuery);
