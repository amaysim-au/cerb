var $interactions_parent = $interaction_container;
var $interactions_menu = $interactions_parent.find('ul.cerb-bot-interactions-menu').menu({
	position: { my: "left middle", at: "right middle", collision: "fit" },
	select: function(event, ui) {
		event.stopPropagation();
		$(ui.item).click();
	}
});

$interactions_parent.find('button.cerb-bot-interactions-button').on('click', function(e) {
	e.stopPropagation();
	$interactions_menu.toggle();
	
	if($interactions_menu.is(':visible'))
		$interactions_menu.menu('focus', null, $interactions_menu.find('.ui-menu-item:first')).focus();
});

$interactions_parent.find('.cerb-bot-trigger')
	.cerbBotTrigger()
	.on('click', function(e) {
		e.stopPropagation();
		$interactions_menu.hide();
	})
;
