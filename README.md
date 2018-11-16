# Forker of PHP


## What is it
Forker is PHP framework with high performance for easily building fast, scalable processer applications. Supports custom commands.
Only Linux and Mac ox


## Installation
     use alim:
     php alim install Processer
     use git:
     git clone https://github.com/ifehrim/processer-case.git 


### example files:

[console](./console.php)




### how to use ?

-  step:1# register forker:
   
        Forker::reg(['Processer Title', process count, __DIR__ . '/logdir/']);
        
        
-  allow actions:
        
        Command [start,stop,restart,status][-d]
        -d  is daemonize process
    
-  step:2# register child process:        
        
        Forker::on('fork', function () {
            
            child process code in here
            
        });

-  step:3# event loop start: 
  
       Forker::run();
       
-  step:4# event loop start: 
        
        php console.php start -d   
       
test result process [start]:

    :) adem processer-case $ php console.php restart -d
    A::Forker-Processer Title restart -d
    A::Forker-Processer Title is stopping ...
    --------------------------------- A::Forker-Processer Title ---------------------------------
    A::Forker-Processer Titleversion:1.0.1     PHP version:7.2.11-4+ubuntu16.04.1+deb.sury.org+1
    --------------------------------- A::Forker-Processer Title ---------------------------------
    Start success.
    
test result process [status]:

    :) adem processer-case $ php console.php status
    A::Forker-Processer Title status
    ----------------------------------------------A::Forker-Processer Title STATUS----------------------------------------------------
    A::Forker-Processer Title version:1.0.1    PHP version:7.2.11-4+ubuntu16.04.1+deb.sury.org+1
    start time:2018-11-16 14:30:11 run     0 days   0 hours    0 minute    2 second
    load average: 0.08, 0.07, 0.02
    1 workers       3 processes
    ----------------------------------------------PROCESS STATUS---------------------------------------------------
    pid     memory  worker_name  timers   status
    4781    2M      none         0           ok
    stat:2
    4782    2M      none         0           ok
    stat:2
    4783    2M      none         0           ok
    stat:2
    
    A::Forker-Processer Title stop status

