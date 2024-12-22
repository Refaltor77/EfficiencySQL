<?php

/*
 *
 *     _____ _           _ _             _____ _             _ _
 *    / ____| |         | | |           / ____| |           | (_)
 *   | (___ | |__  _   _| | | _____ _ _| (___ | |_ _   _  __| |_  ___
 *    \___ \| '_ \| | | | | |/ / _ \ '__\___ \| __| | | |/ _` | |/ _ \
 *    ____) | | | | |_| | |   <  __/ |  ____) | |_| |_| | (_| | | (_) |
 *   |_____/|_| |_|\__,_|_|_|\_\___|_| |_____/ \__|\__,_|\__,_|_|\___/
 *
 *
 *   @author     ShulkerStudio
 *   @developer  Refaltor
 *   @discord    https://shulkerstudio.com/discord
 *   @website    https://shulkerstudio.com
 *
 */

/**
 * @property string $id
 * @property string $created_at
 * @property string $updated_at
 */

namespace refaltor\efficiencySql;

use Exception;
use Generator;
use mysqli;
use pocketmine\scheduler\AsyncTask;
use SOFe\AwaitGenerator\Await;

class Model
{
    protected array $attributes = [];

    /**
     * Retourne le nom de la table associée au modèle.
     *
     * @return string Le nom de la table
     *
     * Exemple d'utilisation :
     *
     * ```
     * $tableName = Model::tableName();
     * echo $tableName;  // Affichera "models" si la classe est Model
     * ```
     */
    protected static function tableName(): string
    {
        return strtolower(static::class).'s';
    }

    /**
     * Définit une propriété magique sur l'objet.
     *
     * @param  string  $name  Le nom de la propriété
     * @param  mixed  $value  La valeur de la propriété
     *
     * Exemple d'utilisation :
     *
     * ```
     * $model = new Model();
     * $model->name = 'John';
     * echo $model->name;  // Affichera 'John'
     * ```
     */
    public function __set($name, $value)
    {
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }

        $this->attributes[$name] = $value;
    }

    /**
     * Retourne la valeur d'une propriété magique.
     *
     * @param  string  $name  Le nom de la propriété
     * @return mixed|null La valeur de la propriété ou null si non définie
     *
     * Exemple d'utilisation :
     *
     * ```
     * $model = new Model();
     * $model->name = 'John';
     * echo $model->name;  // Affichera 'John'
     * echo $model->age;   // Affichera rien car 'age' n'est pas définie
     * ```
     */
    public function __get($name)
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Recharge toutes les valeurs du modèle à partir de la base de données.
     *
     * @return Generator
     *
     * Exemple d'utilisation :
     *
     * ```php
     * $model->reload();
     * ```
     */
    public function reload(): Generator
    {
        $tableName = static::tableName();
        $id = $this->attributes['id'] ?? null;
        $model = $this;

        return yield from Await::promise(function ($resolve) use ($id, $tableName, $model) {
            EfficiencySQL::async(static function (AsyncTask $task, mysqli $db) use ($id, $tableName) {
                $stmt = $db->prepare("SELECT * FROM $tableName WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows !== 0) {
                    $task->setResult($result->fetch_assoc());
                } else {
                    $task->setResult(null);
                }
            }, static function (AsyncTask $task) use ($resolve, $model) {
                $freshData = $task->getResult();

                if ($freshData) {
                    // Mise à jour des attributs du modèle avec les valeurs fraîches
                    $model->attributes = $freshData;
                    $resolve($model);
                }
            });
        });
    }

    /**
     * Sauvegarde le modèle dans la base de données et recharge toutes les valeurs à jour dans le modèle.
     *
     * @return Generator
     *
     * Exemple d'utilisation :
     *
     * ```
     * $model = new Model();
     * $model->name = 'John';
     * yield from $model->save();
     * ```
     * @throws Exception
     */
    public function save(): Generator
    {
        // Récupérer les attributs du modèle
        $attributes = $this->attributes;
        $tableName = static::tableName();
        $model = $this;

        return yield from Await::promise(function ($resolve) use ($attributes, $tableName, $model) {
            // Ajouter les timestamps 'created_at' et 'updated_at'
            $currentTimestamp = date('Y-m-d H:i:s');

            EfficiencySQL::async(static function (AsyncTask $task, mysqli $db) use ($attributes, $tableName, $currentTimestamp) {
                if (isset($attributes['id'])) {
                    // Mise à jour d'un enregistrement existant
                    $attributes['updated_at'] = $currentTimestamp;

                    $columns = array_keys($attributes);
                    $setStatement = implode(', ', array_map(fn ($column) => "$column = ?", $columns));
                    $stmt = $db->prepare("UPDATE $tableName SET $setStatement WHERE id = ?");

                    // Préparer les types et les valeurs pour le bind
                    $types = str_repeat('s', count($attributes));
                    $values = array_values($attributes);
                    $types .= 'i'; // Le dernier type pour 'id' est un entier
                    $values[] = $attributes['id'];

                    $stmt->bind_param($types, ...$values);

                } else {
                    // Création d'un nouvel enregistrement
                    $attributes['created_at'] = $currentTimestamp;
                    $attributes['updated_at'] = $currentTimestamp;

                    $columns = implode(', ', array_keys($attributes));
                    $placeholders = implode(', ', array_fill(0, count($attributes), '?'));

                    $stmt = $db->prepare("INSERT INTO $tableName ($columns) VALUES ($placeholders)");

                    // Préparer les types et les valeurs pour le bind
                    $types = str_repeat('s', count($attributes));
                    $stmt->bind_param($types, ...array_values($attributes));
                }

                if ($stmt->execute()) {
                    if (! isset($attributes['id'])) {
                        // Récupérer l'ID du nouvel enregistrement
                        $attributes['id'] = $db->insert_id;
                    }
                    // Retourner les valeurs mises à jour
                    $task->setResult($attributes['id']);
                } else {
                    // Gestion des erreurs en cas d'échec de l'exécution
                    throw new Exception('Erreur lors de la sauvegarde du modèle : '.$stmt->error);
                }
            }, static function (AsyncTask $task) use ($resolve, $model, $tableName) {
                $savedId = $task->getResult();
                if ($savedId) {
                    // Recharger le modèle à partir de la base de données
                    EfficiencySQL::async(static function (AsyncTask $task, mysqli $db) use ($savedId, $tableName) {
                        $stmt = $db->prepare("SELECT * FROM $tableName WHERE id = ?");
                        $stmt->bind_param('i', $savedId);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $task->setResult($result->fetch_assoc());
                    }, static function (AsyncTask $task) use ($resolve, $model) {
                        $freshData = $task->getResult();
                        if ($freshData) {
                            // Mettre à jour le modèle avec les valeurs fraîchement récupérées
                            $model->attributes = $freshData;
                            // Résoudre la promesse avec le modèle rechargé
                            $resolve($model);
                        }
                    });
                }
            });
        });
    }

    /**
     * Récupère tous les enregistrements de la table et retourne un tableau de modèles.
     *
     * @param string|null $orderBy La colonne à utiliser pour ordonner les résultats
     * @param string|null $orderType Le type d'ordonnancement de la requête (ASC ou DESC)
     * @param int|null $limit Le nombre d'enregistrements à récupérer
     * @return Generator
     *
     * Exemple d'utilisation :
     *
     * ```
     * Model::all(function($models) {
     *     foreach ($models as $model) {
     *         print_r($model);
     *     }
     * });
     * ```
     */
    public static function all(?string $orderBy = null,?string $orderType = null,?int $limit = null): Generator
    {
        $tableName = static::tableName();

        return yield from Await::promise(function ($resolve) use ($tableName,$orderBy,$orderType,$limit) {
            EfficiencySQL::async(function (AsyncTask $task, mysqli $db) use ($tableName,$orderBy,$orderType,$limit) {

                $query = 'SELECT * FROM '.$tableName;
                $query .= $orderBy ? " ORDER BY $orderBy $orderType" : "";
                $query .= $limit ? " LIMIT $limit" : "";

                $result = $db->query($query);
                $rows = [];
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
                $task->setResult($rows);
            },
                function (AsyncTask $task) use ($resolve) {
                    $result = $task->getResult();
                    $models = [];
                    foreach ($result as $row) {
                        $model = new static;

                        foreach ($row as $key => $value) {
                            $model->__set($key, $value);
                        }

                        $models[] = $model;
                    }
                    $resolve($models);
                });
        });
    }

    /**
     * Trouve un enregistrement par son ID et retourne le modèle correspondant.
     *
     * @param int $id L'ID de l'enregistrement à trouver
     * @return Generator Exemple d'utilisation :
     *
     * Exemple d'utilisation :
     *
     * ```
     * Model::find(1, function($model) {
     *     if ($model) {
     *         print_r($model);
     *     } else {
     *         echo "Aucun modèle trouvé.";
     *     }
     * });
     * ```
     */
    public static function find(int $id): Generator
    {
        $tableName = static::tableName();

        return yield from Await::promise(function ($resolve) use ($id, $tableName) {
            EfficiencySQL::async(static function (ASyncTask $task, mysqli $db) use ($id, $tableName) {
                $stmt = $db->prepare('SELECT * FROM '.$tableName.' WHERE id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows !== 0) {
                    $task->setResult($result->fetch_assoc());
                } else {
                    $task->setResult(null);
                }
            }, static function (AsyncTask $task) use ($resolve) {
                $result = $task->getResult();
                if ($result) {
                    $model = new static;
                    foreach ($result as $column => $value) {
                        $model->attributes[$column] = $value;
                    }
                    $resolve($model);
                } else {
                    $resolve(null);
                }
            });
        });
    }

    /**
     * Supprime l'enregistrement actuel de la base de données.
     *
     * @return Generator Exemple d'utilisation :
     *
     * Exemple d'utilisation :
     *
     * ```php
     * $model->delete(function($deletedModel) {
     *     echo 'Modèle supprimé avec succès !';
     * });
     * ```
     */
    public function delete(): Generator
    {
        $attributes = $this->attributes;
        $tableName = static::tableName();
        $model = $this;

        return yield from Await::promise(function ($resolve) use ($model, $tableName, $attributes) {
            if (! isset($attributes['id'])) {
                // Si l'ID n'est pas défini, on ne peut pas effectuer de suppression
                $resolve(null);

                return;
            }

            EfficiencySQL::async(static function (AsyncTask $task, mysqli $db) use ($attributes, $tableName) {
                $stmt = $db->prepare('DELETE FROM '.$tableName.' WHERE id = ?');
                $stmt->bind_param('i', $attributes['id']);

                if ($stmt->execute()) {
                    $task->setResult(true);
                } else {
                    $task->setResult(false);
                }
            }, static function (AsyncTask $task) use ($resolve, $model) {
                $result = $task->getResult();
                if ($result) {
                    $resolve($model);
                } else {
                    $resolve(null);  // Suppression échouée
                }
            });
        });
    }

    /**
     * Récupère les enregistrements de la table qui correspondent aux conditions spécifiées.
     *
     * @param array $conditions Un tableau associatif représentant les colonnes et les valeurs à filtrer
     * @param int|null $limit Le nombre d'enregistrements à récupérer
     * @param int|null $offset L'offset à partir duquel commencer la récupération
     * @param string|null $orderBy La colonne à utiliser pour ordonner les résultats
     * @param string|null $orderType Le type d'ordonnancement de la requête (ASC ou DESC)
     * @return Generator Exemple d'utilisation :
     *
     * Exemple d'utilisation :
     *
     * ```php
     * Model::where(['status' => 'active'], function($models) {
     *     foreach ($models as $model) {
     *         print_r($model);
     *     }
     * });
     * ```
     */
    public static function where(array $conditions,?int $limit = null, ?int $offset = null,?string $orderBy = null,?string $orderType = "ASC"): Generator
    {
        $tableName = static::tableName();

        return yield from Await::promise(function ($resolve) use ($tableName, $conditions,$limit,$offset,$orderBy,$orderType) {
            $query = 'SELECT * FROM '.$tableName.' WHERE ';
            $queryParts = [];
            $types = '';
            $values = [];

            foreach ($conditions as $column => $value) {
                $queryParts[] = "$column = ?";
                // Détecter dynamiquement le type de la valeur
                if (is_int($value)) {
                    $types .= 'i';  // Pour les entiers
                } elseif (is_float($value)) {
                    $types .= 'd';  // Pour les doubles (flottants)
                } else {
                    $types .= 's';  // Par défaut, on considère que c'est une chaîne
                }
                $values[] = $value;
            }

            $query .= implode(' AND ', $queryParts);

            $query .= $orderBy ? " ORDER BY $orderBy $orderType" : "";
            $query .= $limit ? " LIMIT $limit" : "";
            $query .= $offset ? " OFFSET $offset" : "";


            EfficiencySQL::async(function (AsyncTask $task, mysqli $db) use ($query, $types, $values) {
                $stmt = $db->prepare($query);
                $stmt->bind_param($types, ...$values);
                $stmt->execute();
                $result = $stmt->get_result();
                $rows = [];

                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }

                $task->setResult($rows);
            }, function (AsyncTask $task) use ($resolve) {
                $result = $task->getResult();
                $models = [];

                foreach ($result as $row) {
                    $model = new static;
                    foreach ($row as $key => $value) {
                        $model->__set($key, $value);
                    }
                    $models[] = $model;
                }

                if (! is_null($resolve)) {
                    $resolve($models);
                }
            });
        });
    }

    /**
     * Définir une relation "hasMany".
     *
     * @param  string  $related  Le modèle lié
     * @param  string  $foreignKey  La clé étrangère dans le modèle lié
     * @param  string  $localKey  La clé locale dans le modèle actuel
     */
    public function hasMany(string $related, string $foreignKey, string $localKey = 'id'): Generator
    {
        $localValue = $this->{$localKey};

        return yield from $related::where([$foreignKey => $localValue]);
    }

    /**
     * Définir une relation "hasOne".
     *
     * @param  string  $related  Le modèle lié
     * @param  string  $foreignKey  La clé étrangère dans le modèle lié
     * @param  string  $localKey  La clé locale dans le modèle actuel
     */
    public function hasOne(string $related, string $foreignKey, string $localKey = 'id'): Generator
    {
        $localValue = $this->{$localKey};

        $results = yield from $related::where([$foreignKey => $localValue]);

        return $results[0] ?? null;
    }

    /**
     * Définir une relation "belongsTo".
     *
     * @param  string  $related  Le modèle lié
     * @param  string  $foreignKey  La clé étrangère dans le modèle actuel
     * @param  string  $ownerKey  La clé primaire dans le modèle lié
     */
    public function belongsTo(string $related, string $foreignKey, string $ownerKey = 'id'): Generator
    {
        $foreignValue = $this->{$foreignKey};

        return yield from $related::find($foreignValue);
    }
}
