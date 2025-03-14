<?php

namespace Kanboard\Plugin\Calendar\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Filter\TaskAssigneeFilter;
use Kanboard\Filter\TaskDueDateRangeFilter;
use Kanboard\Filter\TaskProjectFilter;
use Kanboard\Filter\TaskStatusFilter;
use Kanboard\Model\TaskModel;

/**
 * Calendar Controller
 *
 * @package  Kanboard\Plugin\Calendar\Controller
 * @author   Frederic Guillot
 * @author   Timo Litzbarski
 */
class CalendarController extends BaseController
{
    public function user()
    {
        $user = $this->getUser();

        $this->response->html($this->helper->layout->app('Calendar:calendar/user', array(
            'user' => $user,
        )));
    }

    public function project()
    {
        $project = $this->getProject();

        $this->response->html($this->helper->layout->app('Calendar:calendar/project', array(
            'project'     => $project,
            'title'       => $project['name'],
            'description' => $this->helper->projectHeader->getDescription($project),
        )));
    }

    public function projectEvents()
    {
        $projectId = $this->request->getIntegerParam('project_id');
        $startRange = $this->request->getStringParam('start');
        $endRange = $this->request->getStringParam('end');
        $search = $this->userSession->getFilters($projectId);
        $startColumn = $this->configModel->get('calendar_project_tasks', 'date_started');

        $dueDateOnlyEvents = $this->taskLexer->build($search)
            ->withFilter(new TaskProjectFilter($projectId))
            ->withFilter(new TaskDueDateRangeFilter(array($startRange, $endRange)))
            ->format($this->taskCalendarFormatter->setColumns('date_due'));


        $startAndDueDateQueryBuilder = $this->taskLexer->build($search)
            ->withFilter(new TaskProjectFilter($projectId));

        $startAndDueDateQueryBuilder
            ->getQuery()
            ->addCondition($this->getConditionForTasksWithStartAndDueDate($startRange, $endRange, $startColumn, 'date_due', 'date_completed'));

        $startAndDueDateEvents = $startAndDueDateQueryBuilder
            ->format($this->taskCalendarFormatter->setColumns($startColumn, 'date_due', 'date_completed'));


        $events = array_merge($dueDateOnlyEvents, $startAndDueDateEvents);

        $events = $this->hook->merge('controller:calendar:project:events', $events, array(
            'project_id' => $projectId,
            'start' => $startRange,
            'end' => $endRange,
            "editable" => true
        ));

        $subtasks = [];
        foreach ($events as $key => $tmp_subtask) {
            if ($events[$key]['backgroundColor'] === null) {
                $parentTask  = $this->taskFinderModel->getById($tmp_subtask['id']);
                if (!isset($subtasks[$tmp_subtask['id']])) {
                    $subtasks[$tmp_subtask['id']] = $this->subtaskModel->getAll($tmp_subtask['id']);
                }

                $subtask = array_filter($subtasks[$tmp_subtask['id']], function ($element, $k) use ($tmp_subtask, $parentTask) {
                    return (t('#%d', $parentTask['id']).' '.$element['title'] == $tmp_subtask['title']);
                }, ARRAY_FILTER_USE_BOTH);

                $subtask = array_shift($subtask);

                $ref = "subtask";
                $color = $parentTask['color_id'];

                if ($color == null || is_int($color)) {
                    $color = $this->colorModel->getBackgroundColor($parentTask['color_id']);
                }

                $events[$key]['id'] = $ref."-".$subtask['id'];
                $events[$key]['backgroundColor'] = $color;
                $events[$key]['borderColor'] =  "black";
            }
        }


        $this->response->json($events);
    }

    public function userEvents()
    {
        $user_id = $this->request->getIntegerParam('user_id');
        $startRange = $this->request->getStringParam('start');
        $endRange = $this->request->getStringParam('end');
        $startColumn = $this->configModel->get('calendar_project_tasks', 'date_started');

        $dueDateOnlyEvents = $this->taskQuery
            ->withFilter(new TaskAssigneeFilter($user_id))
            ->withFilter(new TaskStatusFilter(TaskModel::STATUS_OPEN))
            ->withFilter(new TaskDueDateRangeFilter(array($startRange, $endRange)))
            ->format($this->taskCalendarFormatter->setColumns('date_due'));

        $startAndDueDateQueryBuilder = $this->taskQuery
            ->withFilter(new TaskAssigneeFilter($user_id))
            ->withFilter(new TaskStatusFilter(TaskModel::STATUS_OPEN));

        $startAndDueDateQueryBuilder
            ->getQuery()
            ->addCondition($this->getConditionForTasksWithStartAndDueDate($startRange, $endRange, $startColumn, 'date_due', 'date_completed'));

        $startAndDueDateEvents = $startAndDueDateQueryBuilder
            ->format($this->taskCalendarFormatter->setColumns($startColumn, 'date_due', 'date_completed'));

        $events = array_merge($dueDateOnlyEvents, $startAndDueDateEvents);

        $events = $this->hook->merge('controller:calendar:user:events', $events, array(
            'user_id' => $user_id,
            'start' => $startRange,
            'end' => $endRange,
            "editable" => true
        ));

        $this->response->json($events);
    }

    public function save()
    {
        if ($this->request->isAjax() && $this->request->isPost()) {
            $values = $this->request->getJson();

            $elements = explode("-", $values['task_id']);
            $values['id'] = $elements[1];

            if ($elements[0] === "task") {
                $this->taskModificationModel->update(array(
                'id' => $values['id'],
                'date_due' => substr($values['date_due'], 0, 10),
                'date_started' => substr($values['date_started'], 0, 10),
            ));
            } else {
                $this->subtaskModel->update(array(
                'id' => $values['id'],
                'due_date' => substr($values['date_due'], 0, 10),
            ));
            }
        }
    }

    protected function getConditionForTasksWithStartAndDueDate($startTime, $endTime, $startColumn, $expectedEndColumn, $effectiveEndColumn)
    {
        $startTime = strtotime($startTime);
        $endTime = strtotime($endTime);
        $startColumn = $this->db->escapeIdentifier($startColumn);
        $expectedEndColumn = $this->db->escapeIdentifier($expectedEndColumn);
        $effectiveEndColumn = $this->db->escapeIdentifier($effectiveEndColumn);

        $conditions = array(
            "($startColumn >= '$startTime' AND $startColumn <= '$endTime')",
            "($startColumn <= '$startTime' AND ($expectedEndColumn >= '$startTime' OR $effectiveEndColumn >= '$startTime'))",
            "($startColumn <= '$startTime' AND ($expectedEndColumn = '0' OR $expectedEndColumn IS NULL) AND ($effectiveEndColumn = '0' OR $effectiveEndColumn IS NULL))",
        );

        return $startColumn.' IS NOT NULL AND '.$startColumn.' > 0 AND ('.implode(' OR ', $conditions).')';
    }
}
