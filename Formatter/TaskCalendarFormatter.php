<?php

namespace Kanboard\Plugin\Calendar\Formatter;

use DateTime;
use Kanboard\Core\Filter\FormatterInterface;
use Kanboard\Formatter\BaseFormatter;

/**
 * Calendar event formatter for task filter
 *
 * @package  Kanboard\Plugin\Calendar\Formatter
 * @author   Frederic Guillot
 */
class TaskCalendarFormatter extends BaseFormatter implements FormatterInterface
{
    /**
     * Column used for event start date
     *
     * @access protected
     * @var string
     */
    protected $startColumn = '';

    /**
     * Column used for event expected end date
     *
     * @access protected
     * @var string
     */
    protected $expectedEndColumn = '';

    /**
     * Column used for event effective end date
     *
     * @access protected
     * @var string
     */
    protected $effectiveEndColumn = '';

    /**
     * Transform results to calendar events
     *
     * @access public
     * @param  string  $start_column            Column name for the start date
     * @param  string  $expected_end_column     Column name for the expected end date
     * @param  string  $effective_end_column    Column name for the effective end date
     * @return $this
     */
    public function setColumns($start_column, $expected_end_column = '', $effective_end_column = '')
    {
        $this->startColumn = $start_column;
        $this->expectedEndColumn = $expected_end_column ?: $start_column;
        $this->effectiveEndColumn = $effective_end_column ?: $this->expectedEndColumn;
        return $this;
    }

    /**
     * Transform tasks to calendar events
     *
     * @access public
     * @return array
     */
    public function format()
    {
        $events = array();

        foreach ($this->query->findAll() as $task) {
            $startDate = new DateTime();
            $startDate->setTimestamp($task[$this->startColumn]);

            $endDate = new DateTime();
            if (! empty($task[$this->expectedEndColumn])) {
                $endDate->setTimestamp($task[$this->expectedEndColumn]);
            }

            if ($this->expectedEndColumn != $this->effectiveEndColumn &&
                ! empty($task[$this->effectiveEndColumn]) &&
                $task[$this->effectiveEndColumn] != $task[$this->expectedEndColumn]) {
                $endDate->setTimestamp($task[$this->effectiveEndColumn]);
            }

            $diff = $startDate->diff($endDate, true);
            $allDay = ($diff->format("%a") >= 1);
            $format = $allDay ? 'Y-m-d' : 'Y-m-d\TH:i:s';

            $ref = "task";
            $color = $task['color_id'] ?? null;
            if (!isset($task['color_id']) || $task['color_id'] === null) {
                $parentTask  = $this->taskFinderModel->getById($task['task_id']);
                $ref = "subtask";
                $color = $parentTask['color_id'];
            }

            $id = $ref."-".$task['id'];

            $events[] = array(
                'timezoneParam' => $this->timezoneModel->getCurrentTimezone(),
                'id' => $id,
                'title' => t('#%d', $task['id']).' '.$task['title'],
                'backgroundColor' =>$color,
                'borderColor' => $color,
                'textColor' => 'black',
                'url' => $this->helper->url->to('TaskViewController', 'show', array('task_id' => $task['id'], 'project_id' => $task['project_id'])),
                'start' => $startDate->format($format),
                'end' => $endDate->format($format),
                'editable' => true,
                'allday' => $allDay,
            );
        }

        return $events;
    }
}
