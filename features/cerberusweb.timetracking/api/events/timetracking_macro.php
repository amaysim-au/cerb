<?php
class Event_TimeTrackingMacro extends AbstractEvent_TimeTracking {
	const ID = 'event.macro.timetracking';
	
	function __construct($manifest) {
		parent::__construct($manifest);
		$this->_event_id = self::ID;
	}
	
	static function trigger($trigger_id, $context_id, $variables=array()) {
		$events = DevblocksPlatform::getEventService();
		return $events->trigger(
			new Model_DevblocksEvent(
				self::ID,
				array(
					'context_id' => $context_id,
					'_variables' => $variables,
					'_whisper' => array(
						'_trigger_id' => array($trigger_id),
					),
				)
			)
		);
	}
};