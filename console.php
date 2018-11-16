<?php
/**
 * Created by IntelliJ IDEA.
 * User: pc
 * Date: 11/16/2018
 * Time: 13:58
 */


use Frame\Core\Forker;

include 'Frame/Core/Forker.php';

//Master processer

class state{
    public static $state;
}

Forker::reg(['Processer Title', 3, __DIR__ . '/log/']);

//statistics process data
Forker::on('stat', function () {
    return "stat:".state::$state;
});

//custom command
Forker::on('_start',function (){
    print 'cmd:start';
});

Forker::on('fork', function () {
    //Child processer (copy of master data)
    while (true){
        Forker::signal('loop');
        //todo something
        state::$state++;
        sleep(rand(1,5));
    }
});
Forker::run();