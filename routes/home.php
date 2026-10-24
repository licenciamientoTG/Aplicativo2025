<?php
return [
    route('', [Home::class, 'index'], name: 'home', methods: ['GET']),
    route('home/nas-prueba/<int:n>/', [Home::class, 'nas_prueba'], name: 'home.nas_prueba', methods: ['GET']),
];
