<?php
return [
    group('stations/', [
        route('readings/', [Stations::class, 'readings'], name: 'stations.readings', methods: ['GET', 'POST']),
    ]),
];
