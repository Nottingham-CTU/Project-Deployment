# Generated from Selenium IDE
# Test name: t06 - Events/arms update
import pytest
import time
import json
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support import expected_conditions
from selenium.webdriver.support.wait import WebDriverWait

class Test_06_Events_arms_update:
  def setup_method(self, method):
    self.driver = self.selectedBrowser
    self.vars = {}
  def teardown_method(self, method):
    self.driver.quit()

  def test_06_Events_arms_update(self):
    self.driver.get("http://127.0.0.1/")
    self.driver.find_element(By.LINK_TEXT, "My Projects").click()
    assert len(self.driver.find_elements(By.XPATH, "//*[@id=\"table-proj_table\"][contains(.,'Project Deployment Test 1')]")) > 0
    assert len(self.driver.find_elements(By.XPATH, "//*[@id=\"table-proj_table\"][contains(.,'Project Deployment Test 2')]")) > 0
    self.driver.find_element(By.LINK_TEXT, "Project Deployment Test 1").click()
    self.driver.find_element(By.XPATH, "//button[contains(.,'Define My Events')]").click()
    self.driver.find_element(By.ID, "descrip").click()
    self.driver.find_element(By.ID, "descrip").send_keys("NewEvent")
    self.driver.find_element(By.ID, "addbutton").click()
    WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.XPATH, "//table[@id='event_table']//td[contains(.,'NewEvent')]")))
    self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"arm=3\"]").click()
    WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.ID, "arm_name")))
    self.driver.find_element(By.ID, "arm_name").click()
    self.driver.find_element(By.ID, "arm_name").send_keys("NewArm")
    self.driver.find_element(By.ID, "savebtn").click()
    WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.XPATH, "//*[@id='sub-nav']//a[contains(.,'NewArm')]")))
    self.driver.find_element(By.ID, "descrip").click()
    self.driver.find_element(By.ID, "descrip").send_keys("NewEvent")
    self.driver.find_element(By.ID, "addbutton").click()
    WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.XPATH, "//table[@id='event_table']//td[contains(.,'NewEvent')]")))
    self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"Design/designate_forms.php\"]").click()
    self.driver.find_element(By.ID, "beginEditBtn").click()
    None if len(elements := self.driver.find_elements(By.CSS_SELECTOR, "#save_btn[disabled]")) == 0 else WebDriverWait(self.driver, 30).until(expected_conditions.staleness_of(elements[0]))
    self.driver.execute_script("//SAVEDESC:Select instruments.")
    self.driver.execute_script("$('input[id^=\"visit_lab_data--\"]').last().prop('checked',true)")
    self.driver.find_element(By.ID, "save_btn").click()
    None if len(elements := self.driver.find_elements(By.CSS_SELECTOR, "#beginEditBtn[disabled]")) == 0 else WebDriverWait(self.driver, 30).until(expected_conditions.staleness_of(elements[0]))
    self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"arm=3\"]").click()
    self.driver.find_element(By.ID, "beginEditBtn").click()
    None if len(elements := self.driver.find_elements(By.CSS_SELECTOR, "#save_btn[disabled]")) == 0 else WebDriverWait(self.driver, 30).until(expected_conditions.staleness_of(elements[0]))
    self.driver.execute_script("//SAVEDESC:Select instruments.")
    self.driver.execute_script("$('input[id^=\"visit_lab_data--\"]').last().prop('checked',true)")
    self.driver.find_element(By.ID, "save_btn").click()
    None if len(elements := self.driver.find_elements(By.CSS_SELECTOR, "#beginEditBtn[disabled]")) == 0 else WebDriverWait(self.driver, 30).until(expected_conditions.staleness_of(elements[0]))
    self.driver.find_element(By.LINK_TEXT, "My Projects").click()
    self.driver.find_element(By.LINK_TEXT, "Project Deployment Test 2").click()
    self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"ExternalModules/\"][href*=\"prefix=project_deployment\"]").click()
    self.driver.execute_script("if(window.location.hostname=='127.0.0.1'&&$('input[name=\"username\"]').length>0){$('input[name=\"username\"]').val('admin');$('input[name=\"password\"]').val('abc123');$('form[method=\"post\"] input[type=\"submit\"]').trigger('click')}")
    None if len(elements := self.driver.find_elements(By.CSS_SELECTOR, "input[name=\"username\"]")) == 0 else WebDriverWait(self.driver, 30).until(expected_conditions.staleness_of(elements[0]))
    self.driver.execute_script("//SETDESC:Events/Arms changes identified.")
    self.driver.find_element(By.CSS_SELECTOR, ".changestbl").send_keys("SAVESCREENSHOT")
    self.vars["count"] = self.driver.execute_script("return ''+$('.changestbl tr').length")
    assert(self.vars["count"] == "1")
    assert len(self.driver.find_elements(By.CSS_SELECTOR, "input[name=\"update[events]\"]")) > 0
    None if (element := self.driver.find_element(By.CSS_SELECTOR, "input[name=\"update[events]\"]")).is_selected() else element.click()
    self.driver.find_element(By.CSS_SELECTOR, "input[type=\"submit\"]").click()
    self.driver.execute_script("//SAVEDESC:No changes identified.")
    self.vars["count"] = self.driver.execute_script("return ''+$('.changestbl tr').length")
    assert(self.vars["count"] == "0")
