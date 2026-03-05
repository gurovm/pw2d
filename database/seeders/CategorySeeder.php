<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'l1' => 'Computers & Accessories',
                'l2' => 'Audio & Video Accessories',
                'l3' => 'Computer Headsets, Computer Microphones, Computer Speakers, Webcams'
            ],
            [
                'l1' => 'Computers & Accessories',
                'l2' => 'Keyboards, Mice & Accessories',
                'l3' => 'Keyboards, Mice, Keyboard & Mouse Combos, Mouse Pads, Wrist Rests'
            ],
            [
                'l1' => 'Computers & Accessories',
                'l2' => 'Laptop Accessories',
                'l3' => 'Bags, Cases & Sleeves, Chargers & Adapters, Cooling Pads & External Fans, Lap Desks, Laptop Replacement Batteries'
            ],
            [
                'l1' => 'Computers & Accessories',
                'l2' => 'Monitor Accessories',
                'l3' => 'Monitor Arms & Stands, Privacy Filters, Screen Protectors'
            ],
            [
                'l1' => 'Computers & Accessories',
                'l2' => 'Tablet Accessories',
                'l3' => 'Cases, Covers & Keyboard Folios, Chargers & Adapters, Screen Protectors, Stands, Styluses'
            ],
            [
                'l1' => 'Computers & Accessories',
                'l2' => 'Cables & Accessories',
                'l3' => 'Cables & Interconnects, Cable Security Devices'
            ],
            [
                'l1' => 'Computers & Accessories',
                'l2' => 'Hard Drive Accessories',
                'l3' => 'Hard Drive Enclosures'
            ],
            [
                'l1' => 'Computers & Accessories',
                'l2' => 'Memory Cards',
                'l3' => 'Micro SD Cards, SD Cards'
            ],
            [
                'l1' => 'Computers & Accessories',
                'l2' => 'Power & Hubs',
                'l3' => 'USB Hubs, Uninterruptible Power Supply (UPS)'
            ],
            [
                'l1' => 'Monitors',
                'l2' => 'Monitors',
                'l3' => 'Gaming Monitors, Portable Monitors'
            ],
            [
                'l1' => 'Data Storage',
                'l2' => 'Storage Devices',
                'l3' => 'External Hard Drives, External Solid State Drives (SSD), Network Attached Storage (NAS), USB Flash Drives'
            ],
            [
                'l1' => 'Networking Products',
                'l2' => 'Networking Devices',
                'l3' => 'Modems, Network Switches, Routers, USB Network Adapters, Whole Home & Mesh Wi-Fi Systems'
            ],
            [
                'l1' => 'Printers & Ink',
                'l2' => 'Printers & Scanners',
                'l3' => '3D Printers, Computer Printers, Printer Ink & Toner, Scanners'
            ],
        ];

        foreach ($data as $row) {
            // Level 1
            $l1 = Category::firstOrCreate(
                ['name' => $row['l1']],
                ['slug' => $this->createUniqueSlug($row['l1'])]
            );

            // Level 2
            $l2 = Category::firstOrCreate(
                [
                    'name' => $row['l2'],
                    'parent_id' => $l1->id
                ],
                ['slug' => $this->createUniqueSlug($row['l2'])]
            );

            // Level 3
            $l3Items = array_map('trim', explode(',', $row['l3']));
            foreach ($l3Items as $itemName) {
                if (empty($itemName)) continue;
                
                Category::firstOrCreate(
                    [
                        'name' => $itemName,
                        'parent_id' => $l2->id
                    ],
                    ['slug' => $this->createUniqueSlug($itemName)]
                );
            }
        }
    }

    private function createUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $count = 1;

        while (Category::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$count}";
            $count++;
        }

        return $slug;
    }
}
