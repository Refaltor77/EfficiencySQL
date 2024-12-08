<?php

/*
 *
 *     _____ _           _ _             _____ _             _ _
 *    / ____| |         | | |           / ____| |           | (_)
 *   | (___ | |__  _   _| | | _____ _ _| (___ | |_ _   _  __| |_  ___
 *    \___ \| '_ \| | | | | |/ / _ \ '__\___ \| __| | | |/ _` | |/ _ \
 *    ____) | | | | |_| | |   <  __/ |  ____) | |_| |_| | (_| | | (_) |
 *   |_____/|_| |_|\__,_|_|_|\_\___|_| |_____/ \__|\__,_|\__,_|_|\___/
 *
 *
 *   @author     ShulkerStudio
 *   @developer  Refaltor
 *   @discord    https://shulkerstudio.com/discord
 *   @website    https://shulkerstudio.com
 *
 */

namespace refaltor\efficiencySql;

use Closure;
use mysqli;
use pocketmine\scheduler\AsyncTask;
use pocketmine\thread\NonThreadSafeValue;

class RequestAsync extends AsyncTask
{
    private $async;

    private $resultC;

    private $informations;

    public function __construct(?Closure $async = null, ?Closure $result = null, ?NonThreadSafeValue $informations = null)
    {
        $this->async = $async;
        $this->resultC = $result;
        $this->informations = $informations;
    }

    public function onRun(): void
    {
        $informations = $this->informations->deserialize();
        $db = new mysqli(
            $informations->hostname,
            $informations->username,
            $informations->password,
            $informations->database
        );
        $async = $this->async;
        $async($this, $db);
        $db->close();
    }

    public function onCompletion(): void
    {
        $result = $this->resultC;
        if (! is_null($result)) {
            call_user_func($result, $this);
        }
    }
}
