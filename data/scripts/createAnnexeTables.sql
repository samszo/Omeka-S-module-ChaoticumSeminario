-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Hôte : localhost:3306
-- Généré le : dim. 20 oct. 2024 à 15:22
-- Version du serveur : 10.11.8-MariaDB-0ubuntu0.24.04.1
-- Version de PHP : 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Base de données : `omk_deleuze`
--

-- --------------------------------------------------------

--
-- Structure de la table `concepts`
--

CREATE TABLE `concepts` (
  `id` int(11) NOT NULL,
  `label` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `conferences`
--

CREATE TABLE `conferences` (
  `id` int(11) NOT NULL,
  `titre` varchar(1000) NOT NULL,
  `created` date NOT NULL,
  `source` varchar(1000) NOT NULL,
  `ref` varchar(1000) NOT NULL,
  `promo` varchar(100) NOT NULL,
  `theme` varchar(200) NOT NULL,
  `num` int(11) NOT NULL,
  `sujets` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`sujets`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `disques`
--

CREATE TABLE `disques` (
  `id` int(11) NOT NULL,
  `idConf` int(11) NOT NULL,
  `uri` varchar(1000) NOT NULL,
  `face` int(11) NOT NULL,
  `plage` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `timeline_concept`
--

CREATE TABLE `timeline_concept` (
  `id` int(11) NOT NULL,
  `idConcept` int(11) NOT NULL,
  `idTrans` int(11) NOT NULL,
  `idAnno` int(11) NOT NULL,
  `start` float NOT NULL,
  `end` float NOT NULL,
  `confidence` float NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `trace_fragments`
--

CREATE TABLE `trace_fragments` (
  `id` int(11) NOT NULL,
  `idConf` int(11) NOT NULL,
  `deb` time NOT NULL,
  `fin` time NOT NULL,
  `minCrea` time NOT NULL,
  `maxCrea` time NOT NULL,
  `nb` int(11) NOT NULL,
  `total` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `trace_transcriptions`
--

CREATE TABLE `trace_transcriptions` (
  `id` int(11) NOT NULL,
  `idConf` int(11) NOT NULL,
  `titreConf` varchar(300) NOT NULL,
  `nbFrag` int(11) NOT NULL,
  `deb` datetime NOT NULL,
  `fin` datetime NOT NULL,
  `duree` time NOT NULL,
  `minWhisper` time NOT NULL,
  `maxWhisper` time NOT NULL,
  `minSql` time NOT NULL,
  `maxSql` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `transcriptions`
--

CREATE TABLE `transcriptions` (
  `id` int(11) NOT NULL,
  `idConf` int(11) NOT NULL,
  `idFrag` int(11) NOT NULL,
  `idDisque` int(11) NOT NULL,
  `agent` varchar(64) NOT NULL,
  `texte` varchar(2000) NOT NULL,
  `start` int(11) NOT NULL,
  `end` int(11) NOT NULL,
  `file` varchar(1000) NOT NULL,
  `ref` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `concepts`
--
ALTER TABLE `concepts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `label` (`label`);

--
-- Index pour la table `conferences`
--
ALTER TABLE `conferences`
  ADD PRIMARY KEY (`id`),
  ADD KEY `date` (`created`);

--
-- Index pour la table `disques`
--
ALTER TABLE `disques`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idConf` (`idConf`);

--
-- Index pour la table `timeline_concept`
--
ALTER TABLE `timeline_concept`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idConcept` (`idConcept`),
  ADD KEY `idTrans` (`idTrans`),
  ADD KEY `idAnno` (`idAnno`);

--
-- Index pour la table `trace_fragments`
--
ALTER TABLE `trace_fragments`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `trace_transcriptions`
--
ALTER TABLE `trace_transcriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idConf` (`idConf`);

--
-- Index pour la table `transcriptions`
--
ALTER TABLE `transcriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idConf` (`idConf`),
  ADD KEY `agent` (`agent`),
  ADD KEY `idFrag` (`idFrag`),
  ADD KEY `idDisque` (`idDisque`),
  ADD KEY `transcription_ref` (`ref`);
ALTER TABLE `transcriptions` ADD FULLTEXT KEY `texte` (`texte`);


--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `disques`
--
ALTER TABLE `disques`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `timeline_concept`
--
ALTER TABLE `timeline_concept`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `trace_fragments`
--
ALTER TABLE `trace_fragments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `trace_transcriptions`
--
ALTER TABLE `trace_transcriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;
