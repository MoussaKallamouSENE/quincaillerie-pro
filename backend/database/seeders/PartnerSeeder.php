<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Jeu de donnees de demonstration des partenaires commerciaux.
 *
 * Le seeder charge les clients et les fournisseurs de demonstration. Il est
 * idempotent : chaque partenaire est cree ou mis a jour a partir de son nom,
 * de sorte qu'une seconde execution laisse la base dans le meme etat. Aucun
 * partenaire n'est supprime : les clients et fournisseurs saisis par
 * l'utilisateur restent intacts.
 */
class PartnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Les partenaires d'exemple ne doivent jamais être ajoutés à une base de production.
        if (app()->isProduction()) {
            return;
        }

        $customers = $this->seedCustomers();
        $suppliers = $this->seedSuppliers();

        $this->command?->info(sprintf(
            'Partenaires de demonstration charges : %d clients, %d fournisseurs.',
            count($customers),
            count($suppliers),
        ));
    }

    /**
     * Clients de demonstration.
     *
     * @return list<Customer>
     */
    private function seedCustomers(): array
    {
        $customers = [
            [
                'name' => 'Client Passage',
                'phone' => '+221 77 512 34 56',
                'email' => 'contact@clientpassage.sn',
                'address' => 'Dakar Plateau, rue Sandiniery',
                'credit_limit' => 500000,
            ],
            [
                'name' => 'Moussa Diop',
                'phone' => '+221 76 123 45 67',
                'email' => 'moussa.diop@example.sn',
                'address' => 'Thiès, avenue de la Paix',
                'credit_limit' => 150000,
            ],
            [
                'name' => 'Boutique Chez Ali',
                'phone' => '+221 78 987 65 43',
                'email' => 'boutique.chezali@example.sn',
                'address' => 'Kaolack, quartier Ndiédieng',
                'credit_limit' => 250000,
            ],
        ];

        $seeded = [];

        foreach ($customers as $customer) {
            $seeded[] = Customer::updateOrCreate(
                ['name' => $customer['name']],
                [
                    ...$customer,
                    'outstanding_balance' => 0,
                    'is_active' => true,
                ],
            );
        }

        return $seeded;
    }

    /**
     * Fournisseurs de demonstration.
     *
     * @return list<Supplier>
     */
    private function seedSuppliers(): array
    {
        $suppliers = [
            [
                'name' => 'Cimenterie du Sahel',
                'contact_person' => 'Amadou Fall',
                'phone' => '+221 77 333 22 11',
                'email' => 'contact@cimenterie-sahel.sn',
                'address' => 'Thiès, zone industrielle',
            ],
            [
                'name' => 'Quincaillerie Centrale',
                'contact_person' => 'Fatou Ndiaye',
                'phone' => '+221 70 111 22 33',
                'email' => 'commercial@quincaillerie-centrale.sn',
                'address' => 'Dakar, Rufisque',
            ],
        ];

        $seeded = [];

        foreach ($suppliers as $supplier) {
            $seeded[] = Supplier::updateOrCreate(
                ['name' => $supplier['name']],
                [...$supplier, 'is_active' => true],
            );
        }

        return $seeded;
    }
}
