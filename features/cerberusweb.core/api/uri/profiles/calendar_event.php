<?php
/***********************************************************************
| Cerb(tm) developed by Webgroup Media, LLC.
|-----------------------------------------------------------------------
| All source code & content (c) Copyright 2002-2017, Webgroup Media LLC
|   unless specifically noted otherwise.
|
| This source code is released under the Devblocks Public License.
| The latest version of this license can be found here:
| http://cerb.ai/license
|
| By using this software, you acknowledge having read this license
| and agree to be bound thereby.
| ______________________________________________________________________
|	http://cerb.ai	    http://webgroup.media
***********************************************************************/

class PageSection_ProfilesCalendarEvent extends Extension_PageSection {
	function render() {
		$tpl = DevblocksPlatform::services()->template();
		$request = DevblocksPlatform::getHttpRequest();
		
		$context = CerberusContexts::CONTEXT_CALENDAR_EVENT;
		$active_worker = CerberusApplication::getActiveWorker();
		
		$stack = $request->path;
		@array_shift($stack); // profiles
		@array_shift($stack); // calendar_event
		@$id = intval(array_shift($stack));
		
		if(null == ($event = DAO_CalendarEvent::get($id)))
			return;
		
		$tpl->assign('event', $event);
		
		// Dictionary
		$labels = array();
		$values = array();
		CerberusContexts::getContext($context, $event, $labels, $values, '', true, false);
		$dict = DevblocksDictionaryDelegate::instance($values);
		$tpl->assign('dict', $dict);

		// Remember the last tab/URL
		
		$point = sprintf("cerberusweb.profiles.calendar_event.%d", $event->id);
		$tpl->assign('point', $point);

		// Properties
		
		$translate = DevblocksPlatform::getTranslationService();

		$properties = array();

		$properties['calendar_id'] = array(
			'label' => mb_ucfirst($translate->_('common.calendar')),
			'type' => Model_CustomField::TYPE_LINK,
			'params' => array('context' => CerberusContexts::CONTEXT_CALENDAR),
			'value' => $event->calendar_id,
		);
		
		$properties['date_start'] = array(
			'label' => mb_ucfirst($translate->_('dao.calendar_event.date_start')),
			'type' => null,
			'value' => $event->date_start,
		);
		
		$properties['date_end'] = array(
			'label' => mb_ucfirst($translate->_('dao.calendar_event.date_end')),
			'type' => null,
			'value' => $event->date_end,
		);
		
		$properties['is_available'] = array(
			'label' => mb_ucfirst($translate->_('dao.calendar_event.is_available')),
			'type' => Model_CustomField::TYPE_CHECKBOX,
			'value' => $event->is_available,
		);

		// Custom Fields

		@$values = array_shift(DAO_CustomFieldValue::getValuesByContextIds($context, $event->id)) or array();
		$tpl->assign('custom_field_values', $values);
		
		$properties_cfields = Page_Profiles::getProfilePropertiesCustomFields($context, $values);
		
		if(!empty($properties_cfields))
			$properties = array_merge($properties, $properties_cfields);
		
		// Custom Fieldsets

		$properties_custom_fieldsets = Page_Profiles::getProfilePropertiesCustomFieldsets($context, $event->id, $values);
		$tpl->assign('properties_custom_fieldsets', $properties_custom_fieldsets);
		
		// Link counts
		
		$properties_links = array(
			$context => array(
				$event->id => 
					DAO_ContextLink::getContextLinkCounts(
						$context,
						$event->id,
						array(CerberusContexts::CONTEXT_CUSTOM_FIELDSET)
					),
			),
		);
		
		if(!empty($event->calendar_id)) {
			$properties_links[CerberusContexts::CONTEXT_CALENDAR] = array(
				$event->calendar_id => 
					DAO_ContextLink::getContextLinkCounts(
						CerberusContexts::CONTEXT_CALENDAR,
						$event->calendar_id,
						array(CerberusContexts::CONTEXT_CUSTOM_FIELDSET)
					),
			);
		}
		
		$tpl->assign('properties_links', $properties_links);
		
		// Properties
		
		$tpl->assign('properties', $properties);
		
		// Tabs
		$tab_manifests = Extension_ContextProfileTab::getExtensions(false, $context);
		$tpl->assign('tab_manifests', $tab_manifests);
		
		// Interactions
		$interactions = Event_GetInteractionsForWorker::getInteractionsByPointAndWorker('record:' . $context, $dict, $active_worker);
		$interactions_menu = Event_GetInteractionsForWorker::getInteractionMenu($interactions);
		$tpl->assign('interactions_menu', $interactions_menu);
		
		// Template
		$tpl->display('devblocks:cerberusweb.core::profiles/calendar_event.tpl');
	}
	
	function savePeekJsonAction() {
		@$event_id = DevblocksPlatform::importGPC($_REQUEST['id'],'integer', 0);
		@$name = DevblocksPlatform::importGPC($_REQUEST['name'],'string', '');
		@$date_start = DevblocksPlatform::importGPC($_REQUEST['date_start'],'string', '');
		@$date_end = DevblocksPlatform::importGPC($_REQUEST['date_end'],'string', '');
		@$is_available = DevblocksPlatform::importGPC($_REQUEST['is_available'],'integer', 0);
		@$do_delete = DevblocksPlatform::importGPC($_REQUEST['do_delete'],'integer', 0);
		
		@$view_id = DevblocksPlatform::importGPC($_REQUEST['view_id'],'string', '');

		@$calendar_id = DevblocksPlatform::importGPC($_REQUEST['calendar_id'],'integer', 0);
		
		$active_worker = CerberusApplication::getActiveWorker();
		
		header("Content-type: application/json");
		
		try {
			// Delete
			if(!empty($do_delete) && !empty($event_id)) {
				if(!$active_worker->hasPriv(sprintf("contexts.%s.delete", CerberusContexts::CONTEXT_CALENDAR_EVENT)))
					throw new Exception_DevblocksAjaxValidationError(DevblocksPlatform::translate('error.core.no_acl.delete'));
				
				DAO_CalendarEvent::delete($event_id);
				
				echo json_encode(array(
					'status' => true,
					'id' => intval($event_id),
					'event_id' => intval($event_id),
					'view_id' => $view_id,
					'action' => 'delete',
				));
				return;
			}
			
			// Start/end times
			
			@$timestamp_start = strtotime($date_start);
			
			if(empty($timestamp_start))
				$timestamp_start = time();
			
			@$timestamp_end = strtotime($date_end, $timestamp_start);
	
			if(empty($timestamp_end))
				$timestamp_end = $timestamp_start;
				
			// If the second timestamp is smaller, add a day
			if($timestamp_end < $timestamp_start)
				$timestamp_end = strtotime("+1 day", $timestamp_end);
			
			// Fields
			
			$fields = array(
				DAO_CalendarEvent::NAME => $name,
				DAO_CalendarEvent::DATE_START => $timestamp_start,
				DAO_CalendarEvent::DATE_END => $timestamp_end,
				DAO_CalendarEvent::IS_AVAILABLE => (!empty($is_available)) ? 1 : 0,
				DAO_CalendarEvent::CALENDAR_ID => $calendar_id,
			);
			
			if(empty($event_id)) {
				if(!DAO_CalendarEvent::validate($fields, $error))
					throw new Exception_DevblocksAjaxValidationError($error);
				
				if(!DAO_CalendarEvent::onBeforeUpdateByActor($active_worker, $fields, null, $error))
					throw new Exception_DevblocksAjaxValidationError($error);
				
				$event_id = DAO_CalendarEvent::create($fields);
				DAO_CalendarEvent::onUpdateByActor($active_worker, $fields, $event_id);
				
				// View marquee
				if(!empty($event_id) && !empty($view_id)) {
					C4_AbstractView::setMarqueeContextCreated($view_id, CerberusContexts::CONTEXT_CALENDAR_EVENT, $event_id);
				}
				
			} else {
				if(false == ($calendar_event = DAO_CalendarEvent::get($event_id)))
					return;
				
				$changed_fields = Cerb_ORMHelper::uniqueFields($fields, $calendar_event);
				
				if(!DAO_CalendarEvent::validate($changed_fields, $error, $event_id))
					throw new Exception_DevblocksAjaxValidationError($error);
				
				if(!DAO_CalendarEvent::onBeforeUpdateByActor($active_worker, $fields, $event_id, $error))
					throw new Exception_DevblocksAjaxValidationError($error);
				
				if(!empty($changed_fields)) {
					DAO_CalendarEvent::update($event_id, $changed_fields);
					DAO_CalendarEvent::onUpdateByActor($active_worker, $fields, $event_id);
				}
			}
			
			// Custom fields
			if($event_id) {
				@$field_ids = DevblocksPlatform::importGPC($_REQUEST['field_ids'], 'array', array());
				DAO_CustomFieldValue::handleFormPost(CerberusContexts::CONTEXT_CALENDAR_EVENT, $event_id, $field_ids);
			}
			
			echo json_encode(array(
				'status' => true,
				'id' => intval($event_id),
				'label' => $name,
				'view_id' => $view_id,
				'event_id' => intval($event_id),
				'action' => 'modify',
				'month' => intval(date('m', $timestamp_start)),
				'year' => intval(date('Y', $timestamp_start)),
			));
			return;
			
		} catch (Exception_DevblocksAjaxValidationError $e) {
			echo json_encode(array(
				'status' => false,
				'error' => $e->getMessage(),
				'field' => $e->getFieldName(),
			));
			return;
			
		} catch (Exception $e) {
			echo json_encode(array(
				'status' => false,
				'error' => 'An error occurred.',
			));
			return;
		}
	}
	
};