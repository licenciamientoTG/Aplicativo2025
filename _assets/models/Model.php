<?php
class Model{
    public $sql;
    public $databases;
    public $linked_server;
    public $short_databases;

    function __construct() {
        $this->sql = MySqlPdoHandler::getInstance();
        $this->sql->connect('SG12');
        $this->databases = array(
            0 => "[SG12].[dbo]",                                          // SG12
            2 => "[192.168.7.101].[SG12_41882020].[dbo]",                // Gemela Grande
            3 => "[192.168.28.101].[SG12_11007+2020].[dbo]",             // Aguascalientes
            // 4 es Malecon que ya no existe
            5 => "[192.168.2.101].[SG12_114912020].[dbo]",               // Lerdo
            6 => "[192.168.5.101].[SG12_25262020].[dbo]",                // Lopez Mateos
            7 => "[192.168.6.101].[SG12_4179_20].[dbo]",                 // Gemela Chica
            8 => "[192.168.9.101].[SG12_53172020].[dbo]",                // Municipio Libre
            9 => "[192.168.10.101].[SG12_5465].[dbo]",                   // Aztecas
            10 => "[192.168.11.101].[SG12_6410].[dbo]",                  // Misiones
            11 => "[192.168.19.101].[SG12_6947_2020].[dbo]",             // Puerto de palos
            12 => "[192.168.13.101].[CG_7167].[dbo]",                    // Miguel de la madrid
            13 => "[192.168.14.101].[SG12_8244].[dbo]",                  // Permuta
            14 => "[192.168.15.101].[SG12_9191].[dbo]",                  // Electrolux
            15 => "[192.168.16.101].[SG12_92352020].[dbo]", // Aeronáutica
            16 => "[192.168.17.101].[SG12_98852020].[dbo]",              // Custodia
            17 => "[192.168.18.101].[SG12_9893].[dbo]",                  // Anapra
            18 => "[192.168.4.101].[sg2172].[dbo]",                      // Parral
            19 => "[192.168.3.101].[CG_1376].[dbo]",                     // Delicias
            // 20 es plutarco que no existe (999 PLUTAR 5170)
            21 => "[192.168.8.101].[Custodia5170].[dbo]",                // Plutarco
            22 => "[192.168.30.101].[CG_1163].[dbo]",                    // Tecnológico
            23 => "[192.168.21.101].[CG_9733].[dbo]",                    // Ejército Nacional
            24 => "[192.168.22.101].[CG_4457].[dbo]",                    // Satélite
            25 => "[192.168.23.101].[cg_1159].[dbo]",                    // Las fuentes
            26 => "[192.168.24.101].[CG_1156].[dbo]",                    // Clara
            27 => "[192.168.25.101].[CG_10141].[dbo]",                   // Solis
            28 => "[192.168.26.101].[SG12_12097].[dbo]",                  // Santiago Troncoso
            29 => "[192.168.27.101].[CG_1148].[dbo]",                    // Jarudo
            30 => "[192.168.29.101].[CG_23214].[dbo]",                   // Hermanos Escobar
            31 => "[192.168.32.101].[CG_1242].[dbo]",                    // Villa Ahumada
            32 => "[192.168.33.101].[CG_19190].[dbo]",                   // El castaño
            33 => "[192.168.31.101].[CG_24938].[dbo]",                   // Travel Center
            34 => "[192.168.34.101].[CG_24499].[dbo]",                   // Picachos
            35 => "[192.168.35.101].[CG_24500].[dbo]",                   // Ventanas
            36 => "[192.168.36.101].[CG_14946].[dbo]",                   // SAN RAFAEL
            37 => "[192.168.37.101].[CG_15071].[dbo]",                   // PUERTECITO
            38 => "[192.168.38.101].[CG_15901].[dbo]",                   // JESUS MARIA
        );

        $this->linked_server = array(
            2 => "[192.168.7.101]",                // Gemela Grande
            3 => "[192.168.28.101]",             // Aguascalientes
            // 4 es Malecon que ya no existe
            5 => "[192.168.2.101]",               // Lerdo
            6 => "[192.168.5.101]",                // Lopez Mateos
            7 => "[192.168.6.101]",                 // Gemela Chica
            8 => "[192.168.9.101]",                // Municipio Libre
            9 => "[192.168.10.101]",                   // Aztecas
            10 => "[192.168.11.101]",                  // Misiones
            11 => "[192.168.19.101]",             // Puerto de palos
            12 => "[192.168.13.101]",                    // Miguel de la madrid
            13 => "[192.168.14.101]",                  // Permuta
            14 => "[192.168.15.101]",                  // Electrolux
            15 => "[192.168.16.101]", // Aeronáutica
            16 => "[192.168.17.101]",              // Custodia
            17 => "[192.168.18.101]",                  // Anapra
            18 => "[192.168.4.101]",                      // Parral
            19 => "[192.168.3.101]",                     // Delicias
            // 20 es plutarco que no existe (999 PLUTAR 5170)
            21 => "[192.168.8.101]",                // Plutarco
            22 => "[192.168.30.101]",                    // Tecnológico
            23 => "[192.168.21.101]",                    // Ejército Nacional
            24 => "[192.168.22.101]",                    // Satélite
            25 => "[192.168.23.101]",                    // Las fuentes
            26 => "[192.168.24.101]",                    // Clara
            27 => "[192.168.25.101]",                   // Solis
            28 => "[192.168.26.101]",                  // Santiago Troncoso
            29 => "[192.168.27.101]",                    // Jarudo
            30 => "[192.168.29.101]",                   // Hermanos Escobar
            31 => "[192.168.32.101]",                    // Villa Ahumada
            32 => "[192.168.33.101]",                   // El castaño
            33 => "[192.168.31.101]",                   // Travel Center
            34 => "[192.168.34.101]",                   // Picachos
            35 => "[192.168.35.101]",                   // Ventanas
            36 => "[192.168.36.101]",                   // San rafael
            37 => "[192.168.37.101]",                   // Puertecito
            38 => "[192.168.38.101]",                   // JESUS MARIA
        );

        $this->short_databases = array(
            2 => "[SG12_41882020].[dbo]",                // Gemela Grande
            3 => "[SG12_11007+2020].[dbo]",             // Aguascalientes
            // 4 es Malecon que ya no existe
            5 => "[SG12_114912020].[dbo]",               // Lerdo
            6 => "[SG12_25262020].[dbo]",                // Lopez Mateos
            7 => "[SG12_4179_20].[dbo]",                 // Gemela Chica
            8 => "[SG12_53172020].[dbo]",                // Municipio Libre
            9 => "[SG12_5465].[dbo]",                   // Aztecas
            10 => "[SG12_6410].[dbo]",                  // Misiones
            11 => "[SG12_6947_2020].[dbo]",             // Puerto de palos
            12 => "[CG_7167].[dbo]",                    // Miguel de la madrid
            13 => "[SG12_8244].[dbo]",                  // Permuta
            14 => "[SG12_9191].[dbo]",                  // Electrolux
            15 => "[SG12_92352020].[dbo]", // Aeronáutica
            16 => "[SG12_98852020].[dbo]",              // Custodia
            17 => "[SG12_9893].[dbo]",                  // Anapra
            18 => "[sg2172].[dbo]",                      // Parral
            19 => "[CG_1376].[dbo]",                     // Delicias
            // 20 es plutarco que no existe (999 PLUTAR 5170)
            21 => "[Custodia5170].[dbo]",                // Plutarco
            22 => "[CG_1163].[dbo]",                    // Tecnológico
            23 => "[CG_9733].[dbo]",                    // Ejército Nacional
            24 => "[CG_4457].[dbo]",                    // Satélite
            25 => "[cg_1159].[dbo]",                    // Las fuentes
            26 => "[CG_1156].[dbo]",                    // Clara
            27 => "[CG_10141].[dbo]",                   // Solis
            28 => "[SG12_12097].[dbo]",                  // Santiago Troncoso
            29 => "[CG_1148].[dbo]",                    // Jarudo
            30 => "[CG_23214].[dbo]",                   // Hermanos Escobar
            31 => "[CG_1242].[dbo]",                    // Villa Ahumada
            32 => "[CG_19190].[dbo]",                   // El castaño
            33 => "[CG_24938].[dbo]",                   // Travel Center
            34 => "[CG_24499].[dbo]",                   // Picachos
            35 => "[CG_24500].[dbo]",                   // Ventanas
            36 => "[CG_14946].[dbo]",                   // San rafael
            37 => "[CG_15071].[dbo]",                   // Puertecito
            38 => "[CG_15901].[dbo]",                   // JESUS MARIA
        );
    }
}