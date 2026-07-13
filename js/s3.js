(function ($) {
    'use strict';

    $.files = $.files || {};
    $.files.controller = $.extend($.files.controller || {}, {
        s3Action: function () {
            var $sidebar = $('#f-sidebar');
            $sidebar.find('li.selected').removeClass('selected');
            $sidebar.find('#files-s3-personal-settings-menu-item').addClass('selected');
            this.load('?plugin=s3&module=personal&action=settings');
        }
    });
})(jQuery);
