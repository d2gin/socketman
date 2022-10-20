<?php


namespace icy8\SocketMan\Event;


class Select implements EventInterface
{
    protected $events = [];

    public function __construct()
    {
    }

    public function add($socket, $flag, $caller, $arguments = [])
    {
        $key                       = (int)$socket;
        $this->events[$flag][$key] = compact('caller', 'arguments', 'socket');
    }

    public function delete($socket, $flag)
    {
        $key = (int)$socket;
        if (isset($this->events[$flag][$key])) {
            unset($this->events[$flag][$key]);
        }
    }

    public function dispatch()
    {
    }

    public function getEventResources($flag)
    {
        return array_map(function ($v) {
            return $v['socket'];
        }, $this->events[$flag] ?? []);
    }

    public function loop()
    {
        while (1) {
            $read   = $this->getEventResources(self::EV_READ);
            $write  = $this->getEventResources(self::EV_WRITE);// 暂时搁置
            $except = $this->getEventResources(self::EV_EXCEPT);// 暂时搁置
            if (!$read && !$write && !$except) {
                continue;
            }
            $streamCount = 0;
            try {
                $streamCount = stream_select($read, $write, $except, 0, 1000000);
            } catch (\Throwable $e) {
            }
            if (!$streamCount) continue;
            foreach ($read as $fd) {
                $key      = (int)$fd;
                $dispatch = $this->events[self::EV_READ][$key];
                \call_user_func_array($dispatch['caller'], [$dispatch['socket']]);
            }
        }
    }
}
