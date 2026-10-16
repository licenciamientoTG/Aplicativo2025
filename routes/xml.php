<?php
return [
    group('xml/', [
        route('generatexml/', [Xml::class, 'generatexml'], name: 'xml.generatexml', methods: ['GET']),
    ]),
];
