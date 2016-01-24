# Mrf Synchro Flow package

Description
===========

A Flow (Neos) package for synchronization of the database and resources between instances. 

Useful, when you are working with multiple instances and you need a fast synchronization with other instances data, while developing new features of bugfixing on Development context.

Installation
===========

Install via composer:

``composer require flowpack/neos-frontendlogin:~2.0``

Usage
===========

To download database and resources from remote repository, just run the ``synchro:pull`` command with appropriate instance name as argument. Instance name have to be exactly the same as name for the TYPO3.Surf package, e.g.:

``./flow synchro:pull Production``

If you would like just check what commands will be executed, you can simply run the simulation by ``synchro:simulate`` command, e.g.:

``./flow synchro:simulate Production``

Depending on your configuration, it may be needed to execute your Flow commands in command line using FLOW_CONTEXT environment variable, e.g.:

``FLOW_CONTEXT=Development/Local ./flow synchro:pull Production``

License
=======

Copyright (c) 2016 Karol Lamparski

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

Todo
=======

* Make it possible to work without TYPO3.Surf package (own ssh configuration will be needed)

* Implement push functionality

* Implement rollback functionality

* Implement possibility to block instance from pulling/pushing (to avoid overwrite production instance accidentally)

* Implement possibility to pack (zip/tar) the data before downloading to make it faster

* Implement possiblity to crypt the data for downloading to make it safer

