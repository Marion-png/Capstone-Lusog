<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    protected $fillable = ['name', 'address', 'status'];

    public const DEFAULT_SCHOOLS = [
        'Demo Elementary School',
        'A. L. Navarro National High School',
        'Aurora Quebral Elementary School',
        'Baguio Central Elementary School',
        'Baguio National School of Arts and Trades',
        'Bala ES',
        'Balah Licosan Elementary School',
        'Baracatan National High School',
        'Bernardo D. Carpio National High School',
        'Biao National High School',
        'Binowang National High School',
        'Binugao National High School',
        'Buda National High School',
        'Cabagtukan ES',
        'Cabagbahangan Elementary School',
        'Cabantian National High School',
        'Calinan National High School',
        'Catigan National High School',
        'Congressman Manuel M. Garcia Elementary School',
        'Crossing Bayabas National High School',
        'Dacudao National High School',
        'Daniel R. Aguinaldo National High School',
        'Darila ES',
        'Datas Elementary School',
        'Datu Ansayod Elementary School',
        'Datu Timawa Elementary School',
        'Davao City National High School',
        'Davao City Special National High School',
        'Dominga ES',
        'Doña Carmen Denia National High School',
        'Dr. Santiago Dakudao Sr. National High School',
        'Dumalogdog E/S',
        'E. Ramos National High School',
        'Elias B. Lopez Memorial National High School',
        'Elias P. Dacudao Gumalang School of Home Industries',
        'Erico T. Nograres National High School',
        'F. Bangoy National High School',
        'F. Bustamante National High School',
        'Gorgonio Tajo, Sr. National High School',
        'J. V. Ferriols National High School',
        'Kidali ES',
        'Kiopao Elementary School',
        'Lamanan National High School',
        'Lorenzo Latawan National High School',
        'Ma. Cristina P. Belcar Agricultural High School',
        'Magtuod National High School',
        'Makatao Elementary School',
        'Maluan Elementary School',
        'New Tawas Elementary School',
        'Paraiso Elementary School',
        'Porferio L. Antipala National High School',
        'Salaysay National High School',
        'Saloy National High School',
        'T. Palma Elementary School',
        'Tacunan National High School',
        'Teofilo V. Fernandez National High School',
        'Tungkalan National High School',
        'Vicenta C. Nograles National High School',
        'Wangan National High School',
        'Wireless ES',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public static function seedDefaults(): void
    {
        foreach (self::DEFAULT_SCHOOLS as $name) {
            self::firstOrCreate(['name' => $name], ['status' => 'active']);
        }
    }
}
