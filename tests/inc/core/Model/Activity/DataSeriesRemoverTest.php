<?php

namespace Runalyze\Model\Activity;

use Runalyze\Model\Route;
use Runalyze\Model\Trackdata;

/**
 * Generated by PHPUnit_SkeletonGenerator 1.2.0 on 2015-07-02 at 23:14:19.
 */
class DataSeriesRemoverTest extends \PHPUnit_Framework_TestCase {

	/**
	 * @var \PDO
	 */
	protected $PDO;

	/**
	 * @var \Runalyze\Model\Factory
	 */
	protected $Factory;

	protected $OutdoorID;
	protected $IndoorID;

	protected function setUp() {
		$this->PDO = \DB::getInstance();
		$this->Factory = new \Runalyze\Model\Factory(0);

		$this->PDO->exec('INSERT INTO `'.PREFIX.'sport` (`name`,`kcal`,`outside`,`accountid`,`power`) VALUES("",600,1,0,1)');
		$this->OutdoorID = $this->PDO->lastInsertId();
		$this->PDO->exec('INSERT INTO `'.PREFIX.'sport` (`name`,`kcal`,`outside`,`accountid`,`power`) VALUES("",400,0,0,0)');
		$this->IndoorID = $this->PDO->lastInsertId();

		$this->Factory->clearCache('sport');
		\SportFactory::reInitAllSports();
	}

	protected function tearDown() {
		$this->PDO->exec('DELETE FROM `'.PREFIX.'training`');
		$this->PDO->exec('TRUNCATE TABLE `'.PREFIX.'trackdata`');
		$this->PDO->exec('TRUNCATE TABLE `'.PREFIX.'route`');
		$this->PDO->exec('DELETE FROM `'.PREFIX.'sport`');

		$this->Factory->clearCache('sport');
		\Cache::clean();
	}

	/**
	 * Insert complete activity
	 * @param array $activity
	 * @param array $route
	 * @param array $trackdata
	 * @return int activity id
	 */
	protected function insert(array $activity, array $route, array $trackdata) {
		$activity[Object::ROUTEID] = $this->insertRoute($route);
		$trackdata[Trackdata\Object::ACTIVITYID] = $this->insertActivity($activity, $route, $trackdata);
		$this->insertTrackdata($trackdata);

		return $trackdata[Trackdata\Object::ACTIVITYID];
	}

	/**
	 * @param array $data
	 * @param array $route
	 * @param array $trackdata
	 * @return int
	 */
	protected function insertActivity(array $data, array $route, array $trackdata) {
		$Inserter = new Inserter($this->PDO, new Object($data));
		$Inserter->setRoute(new Route\Object($route));
		$Inserter->setTrackdata(new Trackdata\Object($trackdata));

		return $this->runInserter($Inserter);
	}

	/**
	 * @param array $data
	 * @return int
	 */
	protected function insertRoute(array $data) {
		return $this->runInserter(new Route\Inserter($this->PDO, new Route\Object($data)));
	}

	/**
	 * @param array $data
	 * @return int
	 */
	protected function insertTrackdata(array $data) {
		$this->runInserter(new Trackdata\Inserter($this->PDO, new Trackdata\Object($data)), false);
	}

	/**
	 * @param \Runalyze\Model\InserterWithAccountID $inserter
	 * @return int
	 */
	protected function runInserter(\Runalyze\Model\InserterWithAccountID $inserter, $return = true) {
		$inserter->setAccountID(0);
		$inserter->insert();

		if ($return) {
			return $inserter->insertedID();
		}
	}

	public function testSimpleExample() {
		$id = $this->insert(array(
			Object::TIMESTAMP => time(),
			Object::HR_AVG => 1
		), array(
			Route\Object::GEOHASHES => array('u1xjhpfe7yvs', 'u1xjhzdtjx62', 'u1xjjp6nyp0b'),
			Route\Object::ELEVATIONS_ORIGINAL => array(0, 220, 290),
			Route\Object::ELEVATIONS_CORRECTED => array(210, 220, 230)
		), array(
			Trackdata\Object::TIME => array(300, 600, 900),
			Trackdata\Object::DISTANCE => array(1, 2, 3),
			Trackdata\Object::TEMPERATURE => array(25, 30, 32),
			Trackdata\Object::HEARTRATE => array(0, 250, 130)
		));

		$OldActivity = $this->Factory->activity($id);
		$this->assertTrue($OldActivity->trimp() > 0);

		$Remover = new DataSeriesRemover($this->PDO, 0, $OldActivity, $this->Factory);
		$Remover->removeFromRoute(Route\Object::ELEVATIONS_ORIGINAL);
		$Remover->removeGPSpathFromRoute();
		$Remover->removeFromTrackdata(Trackdata\Object::TEMPERATURE);
		$Remover->removeFromTrackdata(Trackdata\Object::HEARTRATE);
		$Remover->saveChanges();

		$Activity = $this->Factory->activity($id);
		$Route = $this->Factory->route($Activity->get(Object::ROUTEID));
		$Trackdata = $this->Factory->trackdata($id);

		$this->assertFalse($Activity->trimp() > 0);

		$this->assertFalse($Route->has(Route\Object::GEOHASHES));
		$this->assertFalse($Route->hasOriginalElevations());
		$this->assertTrue($Route->hasCorrectedElevations());

		$this->assertTrue($Trackdata->has(Trackdata\Object::TIME));
		$this->assertTrue($Trackdata->has(Trackdata\Object::DISTANCE));
		$this->assertFalse($Trackdata->has(Trackdata\Object::TEMPERATURE));
		$this->assertFalse($Trackdata->has(Trackdata\Object::HEARTRATE));
	}

	public function testIfTrackdataWillBeDeleted() {
		$id = $this->insert(array(
			Object::TIMESTAMP => time()
		), array(
		), array(
			Trackdata\Object::TIME => array(60, 120, 180)
		));

		$OldActivity = $this->Factory->activity($id);

		$Remover = new DataSeriesRemover($this->PDO, 0, $OldActivity, $this->Factory);
		$Remover->removeFromTrackdata(Trackdata\Object::TIME);
		$Remover->saveChanges();

		$Trackdata = $this->Factory->trackdata($id);

		$this->assertTrue($Trackdata->isEmpty());
	}

	public function testIfRouteWillBeDeleted() {
		$id = $this->insert(array(
			Object::TIMESTAMP => time()
		), array(
			Route\Object::GEOHASHES => array('u1xjhpfe7yvs', 'u1xjhzdtjx62', 'u1xjjp6nyp0b'),
			Route\Object::ELEVATIONS_CORRECTED => array(200, 250, 200),
			Route\Object::ELEVATION => 50,
			Route\Object::ELEVATION_UP => 50,
			Route\Object::ELEVATION_DOWN => 50
		), array(
		));

		$OldActivity = $this->Factory->activity($id);
		$RouteID = $OldActivity->get(Object::ROUTEID);

		$Remover = new DataSeriesRemover($this->PDO, 0, $OldActivity, $this->Factory);
		$Remover->removeGPSpathFromRoute();
		$Remover->removeFromRoute(Route\Object::ELEVATIONS_CORRECTED);
		$Remover->saveChanges();

		$Activity = $this->Factory->activity($id);
		$Route = $this->Factory->route($RouteID);

		$this->assertEquals(0, $Activity->get(Object::ROUTEID));
		$this->assertTrue($Route->isEmpty());
	}

	public function testRemovingAverageValues() {
		$id = $this->insert(array(
			Object::TIMESTAMP => time(),
			Object::HR_AVG => 150,
			Object::TEMPERATURE => 18,
			Object::SPORTID => $this->OutdoorID
		), array(
			Route\Object::ELEVATIONS_CORRECTED => array(200, 250, 200)
		), array(
			Trackdata\Object::TEMPERATURE => array(20, 20, 20),
			Trackdata\Object::HEARTRATE => array(150, 170, 130)
		));

		$OldActivity = $this->Factory->activity($id);

		$Remover = new DataSeriesRemover($this->PDO, 0, $OldActivity, $this->Factory);
		$Remover->removeFromTrackdata(Trackdata\Object::TEMPERATURE);
		$Remover->removeFromTrackdata(Trackdata\Object::HEARTRATE);
		$Remover->saveChanges();

		$Activity = $this->Factory->activity($id);
		$this->assertEquals(18, $Activity->weather()->temperature()->value());
		$this->assertEquals(0, $Activity->hrAvg());
	}

}
